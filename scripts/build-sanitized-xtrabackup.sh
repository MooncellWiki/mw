#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
    cat <<'EOF'
Usage:
  build-sanitized-xtrabackup.sh BACKUP_CHUNKS_DIR ENCRYPT_KEY_FILE [OUTPUT_DIR]

Arguments:
  BACKUP_CHUNKS_DIR
      Directory containing the xbcloud objects downloaded from object storage. The
      expected input was originally uploaded from an encrypted, compressed xbstream;
      files normally look like:

        ak/akpage.ibd.zst.xbcrypt.00000000000000000000
        ak/akpage.ibd.zst.xbcrypt.00000000000000000001
        xtrabackup_checkpoints.00000000000000000000

  ENCRYPT_KEY_FILE
      AES256 key file used by the source XtraBackup. A trailing CR/LF is accepted;
      after removing line endings the key must be exactly 32 bytes. The normalized
      key is stored in a mode-0600 temporary file, mounted read-only into the
      decrypting container, removed before MySQL starts, and never included in the
      result.

  OUTPUT_DIR
      New output directory. It must not already exist. Defaults to
      ./prts-sanitized-YYYY-MM-DD-HHMMSS.

Requirements:
  Docker and zstd must be available. The script uses these container images:

    percona/percona-xtrabackup:8.0
    mysql:8.0.43
    alpine:3.20

  The three MySQL datadirs are held in Docker named volumes, so the space they
  need is space in Docker's storage area, not under OUTPUT_DIR. Budget roughly 4x
  the prepared datadir there; OUTPUT_DIR itself only has to hold the compressed
  release and the logical dump. The scratch datadir, the only one that ever holds
  unredacted data, is destroyed as soon as the dump completes.

  Building on macOS works: because the datadirs are volumes rather than bind
  mounts they sit on the Linux VM's case-sensitive filesystem, which is what
  lower_case_table_names=0 and MediaWiki's mixed-case table names require.

Why this script rebuilds the database instead of redacting it in place:
  A previous revision sanitized the restored datadir with UPDATE/TRUNCATE and then
  ran XtraBackup against that same datadir. XtraBackup is a *physical* copy, and
  InnoDB does not zero the bytes of superseded rows: old row versions survive in
  page free space, in the undo tablespaces, in ibdata1 and in mysql.ibd. An audit
  written in SQL sees only live rows and therefore passes, while `grep` on the
  shipped .ibd files still recovers the redacted values. That revision leaked, among
  other things, ~105k user e-mail addresses out of ak/akuser.ibd and the
  caching_sha2 password hashes of the production MySQL accounts out of mysql.ibd.

  The only reliable fix is to never ship pages that ever held sensitive bytes. This
  script therefore sanitizes a scratch instance, dumps it logically, imports the
  dump into a brand-new server whose tablespaces were created from nothing, and
  backs up *that* server. Step 9 greps the finished artifact and fails the build if
  anything survived.

Workflow:
   1. Sort and join downloaded xbcloud objects back into xbstream.
   2. Extract, AES256-decrypt, Zstandard-decompress, and prepare the source backup.
   3. Drop production binary logs from the prepared datadir.
   4. Start an isolated scratch MySQL container with --skip-grant-tables, so the
      production root password is not needed and no production account is usable.
   5. Run the embedded sanitization SQL against ak.
   6. Require every result from the pre-dump audit to be zero.
   7. Dump ak logically, then delete the scratch datadir.
   8. Initialize a brand-new MySQL server, import the dump, create the dev accounts,
      and rerun the audit against it.
   9. Back up the new server, then grep the decompressed artifact for e-mail
      addresses, production account names and dropped schema names. Any hit fails.
  10. Prepare an independent restore, rerun the audit, and check core tables.
  11. Produce a tar archive and SHA256SUMS, then remove prepared temporary datadirs.

Output:
  OUTPUT_DIR/
  ├── SHA256SUMS
  ├── audit-pre-dump.tsv
  ├── audit-post-import.tsv
  ├── audit-post-restore.tsv
  ├── bytescan.tsv
  ├── check-table.tsv
  ├── prts-ak-sanitized.xtrabackup.tar
  └── xtrabackup/

  The tar and xtrabackup/ directory contain the same compressed full backup. Only
  one needs to be distributed. Verify SHA256SUMS before publishing the tar.

Safety:
  The source backup and encryption key are never modified. Set KEEP_WORK=1 to retain
  the temporary datadirs and the logical dump for debugging. Those contain sensitive
  plaintext data and must never be committed or distributed.

Example:
  ./build-sanitized-xtrabackup.sh \
    /path/to/downloaded-backup \
    /path/to/encrypt-key \
    /path/to/new-output-directory
EOF
}

die() {
    echo "ERROR: $*" >&2
    exit 1
}

log() {
    printf '\n[%s] %s\n' "$(date '+%F %T')" "$*"
}

# ---------------------------------------------------------------------------
# Table inventory
#
# Tables are listed by name rather than hardcoded into the SQL so that a schema
# that has drifted -- an extension added, removed or renamed since this script was
# written -- does not abort the run with "table doesn't exist", and so that a table
# added by a future extension shows up in the unclassified-table report instead of
# being silently shipped.
# ---------------------------------------------------------------------------

# Emptied completely: credentials, private logs, notification state, social data,
# transient caches, and anything CheckUser-adjacent.
TRUNCATE_TABLES="
abuse_filter
abuse_filter_action
abuse_filter_history
abuse_filter_log
archive
filearchive
bot_passwords
oathauth_devices
oauth2_access_tokens
oauth_accepted_consumer
oauth_registered_consumer
cu_changes
cu_log
cu_log_event
cu_private_event
cu_useragent
cu_useragent_clienthints
cu_useragent_clienthints_map
cuci_temp_edit
cuci_user
cuci_wiki_map
echo_email_batch
echo_event
echo_notification
echo_push_provider
echo_push_subscription
echo_push_topic
echo_target_page
echo_unread_wikis
watchlist_expiry
watchlist
user_properties
user_profile
user_board
user_relationship
user_relationship_request
user_gift
user_system_gift
user_system_messages
user_stats
user_points_archive
user_points_monthly
user_points_weekly
user_fields_privacy
objectcache
job
uploadstash
ip_changes
block
block_target
ipblocks_restrictions
searchindex
ed_url_cache
FlowThread
FlowThreadAttitude
FlowThreadControl
flow_revision
flow_tree_revision
flow_tree_node
flow_topic_list
flow_workflow
flow_ext_ref
flow_wiki_ref
"

# Link and property rows derived from page content, keyed by the source page id.
# Blanking the wikitext of a user page leaves these behind: externallinks in
# particular still holds every personal URL the page linked to. All of them are
# rebuildable with refreshLinks.php, so dropping the user-namespace rows costs a
# dev environment nothing.
# Format: "table:page_id_column"
DERIVED_FROM_PAGE="
pagelinks:pl_from
templatelinks:tl_from
imagelinks:il_from
categorylinks:cl_from
externallinks:el_from
iwlinks:iwl_from
langlinks:ll_from
page_props:pp_page
"

# Title columns rewritten to a hash for the user (2) and user-talk (3) namespaces.
# Format: "table:title_column:namespace_column"
NS_TITLE_COLUMNS="
page:page_title:page_namespace
linktarget:lt_title:lt_namespace
logging:log_title:log_namespace
recentchanges:rc_title:rc_namespace
protected_titles:pt_title:pt_namespace
querycache:qc_title:qc_namespace
querycachetwo:qcc_title:qcc_namespace
querycachetwo:qcc_titletwo:qcc_namespacetwo
redirect:rd_title:rd_namespace
smw_object_ids:smw_title:smw_namespace
smw_fpt_redi:s_title:s_namespace
"

# Known-safe: wiki content, game data, schema metadata, rebuildable caches. Listed
# so that step 5b can report any table belonging to none of these groups.
KNOWN_SAFE_TABLES="
actor user user_groups user_former_groups user_newtalk user_autocreate_serial
comment content content_models slots slot_roles text revision page page_props
page_restrictions pagelinks templatelinks imagelinks categorylinks category
externallinks iwlinks langlinks redirect linktarget protected_titles
querycache querycache_info querycachetwo recentchanges logging log_search
change_tag change_tag_def image oldimage sites site_identifiers site_stats
interwiki l10n_cache module_deps updatelog spritename spritename_rev
spritesheet spritesheet_rev gift system_gift ajaxpoll_info ajaxpoll_vote
oathauth_types
"

emit_truncates() {
    local t
    for t in $TRUNCATE_TABLES; do
        table_exists "$t" && printf 'TRUNCATE TABLE `%s%s`;\n' "$PFX" "$t"
    done
    return 0
}

emit_derived_link_purge() {
    local spec table col
    for spec in $DERIVED_FROM_PAGE; do
        IFS=: read -r table col <<<"$spec"
        table_exists "$table" || continue
        column_exists "$table" "$col" || continue
        printf 'DELETE d FROM `%s%s` d JOIN `%spage` p ON p.page_id = d.`%s` WHERE p.page_namespace IN (2, 3);\n' \
            "$PFX" "$table" "$PFX" "$col"
    done
    return 0
}

emit_derived_link_audit() {
    local spec table col first=1
    printf "SELECT 'derived_rows_from_user_pages',\n"
    for spec in $DERIVED_FROM_PAGE; do
        IFS=: read -r table col <<<"$spec"
        table_exists "$table" || continue
        column_exists "$table" "$col" || continue
        [[ $first -eq 1 ]] && first=0 || printf ' +\n'
        printf '    (SELECT COUNT(*) FROM `%s%s` d JOIN `%spage` p ON p.page_id = d.`%s` WHERE p.page_namespace IN (2, 3))' \
            "$PFX" "$table" "$PFX" "$col"
    done
    [[ $first -eq 1 ]] && printf '    0'
    printf ';\n'
    return 0
}

emit_ns_title_rewrites() {
    local spec table col nscol
    for spec in $NS_TITLE_COLUMNS; do
        IFS=: read -r table col nscol <<<"$spec"
        table_exists "$table" || continue
        column_exists "$table" "$col" || continue
        printf 'UPDATE `%s%s` SET `%s` = CONCAT('"'"'Sanitized_'"'"', LEFT(SHA2(`%s`, 256), 16)) WHERE `%s` IN (2, 3);\n' \
            "$PFX" "$table" "$col" "$col" "$nscol"
    done
    return 0
}

emit_sanitize_sql() {
    cat <<SQL
-- Run only against an isolated copy. Never run against the production snapshot.
SET SESSION sql_log_bin = 0;
SET SESSION foreign_key_checks = 0;
SET SESSION unique_checks = 0;

DROP DATABASE IF EXISTS mooncell;
DROP DATABASE IF EXISTS my_wiki;
DROP DATABASE IF EXISTS test;

-- ---------------------------------------------------------------------------
-- 1. Content of user and user-talk pages.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS prts_private_text_ids;
CREATE TEMPORARY TABLE prts_private_text_ids (
    old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB
SELECT DISTINCT CAST(SUBSTRING(c.content_address, 4) AS UNSIGNED) AS old_id
FROM ${PFX}page p
JOIN ${PFX}revision r ON r.rev_page = p.page_id
JOIN ${PFX}slots s ON s.slot_revision_id = r.rev_id
JOIN ${PFX}content c ON c.content_id = s.slot_content_id
WHERE p.page_namespace IN (2, 3) AND c.content_address LIKE 'tt:%';

UPDATE ${PFX}text t JOIN prts_private_text_ids x ON x.old_id = t.old_id
SET t.old_text = '$REDACTED_MARKER';
DROP TEMPORARY TABLE prts_private_text_ids;

-- ---------------------------------------------------------------------------
-- 2. Content of revisions hidden by RevisionDelete or Oversight. MediaWiki
--    enforces rev_deleted in the application layer only; the text row stays
--    fully readable to anyone holding the database.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS prts_deleted_text_ids;
CREATE TEMPORARY TABLE prts_deleted_text_ids (
    old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB
SELECT DISTINCT CAST(SUBSTRING(c.content_address, 4) AS UNSIGNED) AS old_id
FROM ${PFX}revision r
JOIN ${PFX}slots s ON s.slot_revision_id = r.rev_id
JOIN ${PFX}content c ON c.content_id = s.slot_content_id
WHERE r.rev_deleted <> 0 AND c.content_address LIKE 'tt:%';

UPDATE ${PFX}text t JOIN prts_deleted_text_ids x ON x.old_id = t.old_id
SET t.old_text = '$REDACTED_MARKER';
DROP TEMPORARY TABLE prts_deleted_text_ids;

UPDATE ${PFX}revision SET rev_deleted = 0 WHERE rev_deleted <> 0;
DELETE FROM ${PFX}logging WHERE log_deleted <> 0;

-- ---------------------------------------------------------------------------
-- 3. Text rows no longer reachable from any live revision. Truncating archive
--    removes the index of deleted revisions but not their content; every
--    deleted page, every reverted vandalism edit and everything ever
--    suppressed stays in the text table as an unreferenced row.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS prts_live_text_ids;
CREATE TEMPORARY TABLE prts_live_text_ids (
    old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB
SELECT DISTINCT CAST(SUBSTRING(c.content_address, 4) AS UNSIGNED) AS old_id
FROM ${PFX}revision r
JOIN ${PFX}slots s ON s.slot_revision_id = r.rev_id
JOIN ${PFX}content c ON c.content_id = s.slot_content_id
WHERE c.content_address LIKE 'tt:%';

DELETE t FROM ${PFX}text t
LEFT JOIN prts_live_text_ids x ON x.old_id = t.old_id
WHERE x.old_id IS NULL;
DROP TEMPORARY TABLE prts_live_text_ids;

-- ---------------------------------------------------------------------------
-- 4. Page titles in the user and user-talk namespaces.
-- ---------------------------------------------------------------------------
$(emit_ns_title_rewrites)

-- Hashing log_title is not enough: log_params carries the old and new titles of
-- every page move as serialized PHP, so moving a user page records the real
-- account name there. This wiki uses localized namespace names, so the prefix
-- test has to cover them as well as the canonical English ones.
UPDATE ${PFX}logging SET log_params = ''
WHERE log_namespace IN (2, 3)
   OR log_params LIKE '%User:%'
   OR log_params LIKE '%User talk:%'
   OR log_params LIKE '%User_talk:%'
   OR log_params LIKE '%用户:%'
   OR log_params LIKE '%用戶:%'
   OR log_params LIKE '%用户讨论:%'
   OR log_params LIKE '%用戶討論:%';

-- ---------------------------------------------------------------------------
-- 4b. Link and property rows extracted from user-namespace page content.
-- ---------------------------------------------------------------------------
$(emit_derived_link_purge)

-- ---------------------------------------------------------------------------
-- 5. Account identity. user_token is regenerated rather than zeroed so that
--    every row keeps a distinct, well-formed value.
-- ---------------------------------------------------------------------------
UPDATE ${PFX}user
SET user_name = CONCAT('User_', LPAD(user_id, 6, '0')),
    user_real_name = '', user_password = '!', user_newpassword = '',
    user_newpass_time = NULL, user_email = '',
    user_token = UNHEX(SHA2(CONCAT('prts-sanitized-user-', user_id), 256)),
    user_email_authenticated = NULL, user_email_token = NULL,
    user_email_token_expires = NULL, user_password_expires = NULL;

UPDATE ${PFX}actor
SET actor_name = CASE
    WHEN actor_user IS NULL THEN CONCAT('Anonymous_', LPAD(actor_id, 8, '0'))
    ELSE CONCAT('User_', LPAD(actor_user, 6, '0'))
END;

-- ---------------------------------------------------------------------------
-- 6. Edit summaries. Pseudonymizing user and actor rows accomplishes nothing
--    while free-text summaries still say who was reverted: comment_text holds
--    real account names in rollback and undo messages, and occasionally raw
--    IPv4 addresses. Names cannot be mapped back to their pseudonyms reliably
--    inside free text, so any summary carrying an identity reference is
--    replaced wholesale; summaries without one are preserved.
-- ---------------------------------------------------------------------------
UPDATE ${PFX}comment SET comment_text = '$REDACTED_MARKER', comment_data = NULL
WHERE comment_text LIKE '%[[User:%'
   OR comment_text LIKE '%[[User talk:%'
   OR comment_text LIKE '%[[user:%'
   OR comment_text LIKE '%Special:Contributions%'
   OR comment_text LIKE '%[[用户:%'
   OR comment_text LIKE '%[[用戶:%'
   OR comment_text LIKE '%[[User_talk:%'
   OR CAST(comment_text AS CHAR CHARACTER SET utf8mb4) REGEXP _utf8mb4'[0-9]{1,3}[.][0-9]{1,3}[.][0-9]{1,3}[.][0-9]{1,3}'
   OR CAST(comment_text AS CHAR CHARACTER SET utf8mb4) REGEXP _utf8mb4'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}';

-- ---------------------------------------------------------------------------
-- 7. Remaining IP columns on tables that are kept.
-- ---------------------------------------------------------------------------
UPDATE ${PFX}recentchanges SET rc_ip = '';
UPDATE ${PFX}ajaxpoll_vote SET poll_ip = NULL;
UPDATE ${PFX}user_newtalk SET user_ip = '';

-- ---------------------------------------------------------------------------
-- 8. Bulk removals.
-- ---------------------------------------------------------------------------
$(emit_truncates)

SET SESSION unique_checks = 1;
SET SESSION foreign_key_checks = 1;
SQL
}

emit_create_accounts_sql() {
    cat <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'akdev';
CREATE USER IF NOT EXISTS 'akdev'@'%' IDENTIFIED WITH mysql_native_password BY 'akdev';
GRANT ALL PRIVILEGES ON ak.* TO 'akdev'@'%';
GRANT BACKUP_ADMIN, PROCESS, RELOAD, LOCK TABLES, REPLICATION CLIENT ON *.* TO 'akdev'@'%';
GRANT SELECT ON performance_schema.* TO 'akdev'@'%';
FLUSH PRIVILEGES;
SQL
}

# $1: pass 1 to also audit MySQL accounts, 0 to skip. The scratch instance keeps
# the production accounts on purpose. Only the ak schema is dumped out of it, so
# its mysql schema never reaches the artifact, and dropping accounts on a
# --skip-grant-tables server would be busywork. The rebuilt and restored
# instances are the ones that ship, and there the check must run.
emit_audit_sql() {
    local include_accounts=${1:-1}
    cat <<SQL
SELECT 'unexpected_schema', COUNT(*) FROM information_schema.schemata
WHERE schema_name NOT IN ('ak', 'information_schema', 'mysql', 'performance_schema', 'sys');

SELECT 'bad_user_identity', COUNT(*) FROM ${PFX}user
WHERE CAST(user_name AS CHAR CHARACTER SET utf8mb4) NOT REGEXP _utf8mb4'^User_[0-9]{6}\$'
   OR user_real_name <> '' OR user_email <> '' OR user_password <> '!'
   OR user_newpassword <> '' OR user_email_token IS NOT NULL;

SELECT 'bad_actor_identity', COUNT(*) FROM ${PFX}actor
WHERE CAST(actor_name AS CHAR CHARACTER SET utf8mb4) NOT REGEXP _utf8mb4'^(User_[0-9]{6}|Anonymous_[0-9]{8})\$';

SELECT 'bad_user_page_title', COUNT(*) FROM ${PFX}page WHERE page_namespace IN (2, 3)
AND CAST(page_title AS CHAR CHARACTER SET utf8mb4) NOT REGEXP _utf8mb4'^Sanitized_[0-9a-f]{16}\$';

SELECT 'unredacted_user_page_text', COUNT(*)
FROM ${PFX}page p
JOIN ${PFX}revision r ON r.rev_page = p.page_id
JOIN ${PFX}slots s ON s.slot_revision_id = r.rev_id
JOIN ${PFX}content c ON c.content_id = s.slot_content_id
JOIN ${PFX}text t ON t.old_id = CAST(SUBSTRING(c.content_address, 4) AS UNSIGNED)
WHERE p.page_namespace IN (2, 3) AND c.content_address LIKE 'tt:%'
  AND t.old_text <> '$REDACTED_MARKER';

-- content_address carries no index, so a correlated NOT EXISTS over the text
-- table degenerates into one full scan per row. Materialize the live set once.
DROP TEMPORARY TABLE IF EXISTS prts_audit_live_ids;
CREATE TEMPORARY TABLE prts_audit_live_ids (
    old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB
SELECT DISTINCT CAST(SUBSTRING(c.content_address, 4) AS UNSIGNED) AS old_id
FROM ${PFX}revision r
JOIN ${PFX}slots s ON s.slot_revision_id = r.rev_id
JOIN ${PFX}content c ON c.content_id = s.slot_content_id
WHERE c.content_address LIKE 'tt:%';

SELECT 'orphaned_text_rows', COUNT(*) FROM ${PFX}text t
LEFT JOIN prts_audit_live_ids x ON x.old_id = t.old_id
WHERE x.old_id IS NULL;

DROP TEMPORARY TABLE prts_audit_live_ids;

SELECT 'revisions_still_flagged_deleted', COUNT(*) FROM ${PFX}revision WHERE rev_deleted <> 0;

SELECT 'comments_with_identity', COUNT(*) FROM ${PFX}comment
WHERE comment_text LIKE '%[[User:%' OR comment_text LIKE '%[[user:%'
   OR comment_text LIKE '%[[User talk:%' OR comment_text LIKE '%[[User_talk:%'
   OR comment_text LIKE '%Special:Contributions%'
   OR comment_text LIKE '%[[用户:%' OR comment_text LIKE '%[[用戶:%'
   OR CAST(comment_text AS CHAR CHARACTER SET utf8mb4) REGEXP _utf8mb4'[0-9]{1,3}[.][0-9]{1,3}[.][0-9]{1,3}[.][0-9]{1,3}'
   OR CAST(comment_text AS CHAR CHARACTER SET utf8mb4) REGEXP _utf8mb4'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+[.][A-Za-z]{2,}';

SELECT 'external_store_addresses', COUNT(*) FROM ${PFX}content
WHERE content_address NOT LIKE 'tt:%';

SELECT 'logging_params_with_user_title', COUNT(*) FROM ${PFX}logging
WHERE log_namespace IN (2, 3) AND log_params <> ''
   OR log_params LIKE '%User:%' OR log_params LIKE '%User talk:%'
   OR log_params LIKE '%User_talk:%' OR log_params LIKE '%用户:%'
   OR log_params LIKE '%用戶:%' OR log_params LIKE '%用户讨论:%'
   OR log_params LIKE '%用戶討論:%';

$(emit_derived_link_audit)

SELECT 'remaining_ip',
    (SELECT COUNT(*) FROM ${PFX}recentchanges WHERE rc_ip <> '') +
    (SELECT COUNT(*) FROM ${PFX}ajaxpoll_vote WHERE poll_ip IS NOT NULL) +
    (SELECT COUNT(*) FROM ${PFX}user_newtalk WHERE user_ip <> '');

$(emit_truncate_audit)

SQL

    [[ $include_accounts == 1 ]] || return 0
    cat <<SQL

SELECT 'unexpected_mysql_account', COUNT(*) FROM mysql.user
WHERE user NOT IN ('mysql.infoschema', 'mysql.session', 'mysql.sys', 'root', 'akdev');

SELECT 'view_with_foreign_definer', COUNT(*) FROM information_schema.views
WHERE table_schema = '$DB_NAME'
  AND (security_type <> 'INVOKER' OR definer NOT LIKE 'root@%');
SQL
}

emit_truncate_audit() {
    local t first=1
    printf "SELECT 'private_or_transient_rows',\n"
    for t in $TRUNCATE_TABLES; do
        table_exists "$t" || continue
        if [[ $first -eq 1 ]]; then
            first=0
            printf '    (SELECT COUNT(*) FROM `%s%s`)' "$PFX" "$t"
        else
            printf ' +\n    (SELECT COUNT(*) FROM `%s%s`)' "$PFX" "$t"
        fi
    done
    [[ $first -eq 1 ]] && printf '    0'
    printf ';\n'
    return 0
}

# ---------------------------------------------------------------------------
# Argument handling
# ---------------------------------------------------------------------------

if [[ ${1:-} == '-h' || ${1:-} == '--help' ]]; then
    usage
    exit 0
fi

[[ $# -ge 2 && $# -le 3 ]] || {
    usage >&2
    exit 2
}

# realpath -m is a GNU extension: it canonicalizes a path whose final component
# does not exist yet, which is exactly what OUTPUT_DIR is. BSD realpath (macOS)
# has no such flag and fails outright, so resolve the parent and re-append.
abs_path() {
    local p=$1 dir base
    case $p in
        /*) ;;
        *) p="$PWD/$p" ;;
    esac
    dir=$(dirname "$p")
    base=$(basename "$p")
    if [[ -d $dir ]]; then
        printf '%s/%s\n' "$(cd "$dir" && pwd -P)" "$base"
    else
        printf '%s\n' "$p"
    fi
}

backup_dir=$(realpath "$1")
key_file=$(realpath "$2")
if [[ $# -eq 3 ]]; then
    output_dir=$(abs_path "$3")
else
    output_dir=$(abs_path "prts-sanitized-$(date '+%F-%H%M%S')")
fi

[[ -d $backup_dir ]] || die "backup directory does not exist: $backup_dir"
[[ -f $key_file ]] || die "encryption key file does not exist: $key_file"
[[ -r $key_file ]] || die "encryption key file is not readable: $key_file"
[[ -s $key_file ]] || die "encryption key file is empty: $key_file"
[[ ! -e $output_dir ]] || die "output path already exists: $output_dir"
command -v docker >/dev/null || die "docker is required"
docker info >/dev/null 2>&1 || die "docker daemon is not available"
command -v zstd >/dev/null || die "zstd is required (used to compress the logical dump)"

chunk_count=$(find "$backup_dir" -type f -name '*.00000000000000000000' -print | wc -l)
[[ $chunk_count -gt 0 ]] || die "no xbcloud chunk files found under $backup_dir"

case "$output_dir" in
    /|/home|/home/*/Documents)
        die "refusing unsafe output path: $output_dir"
        ;;
esac

PFX=${DB_PREFIX-ak}
DB_NAME=${DB_NAME-ak}
REDACTED_MARKER='[content removed from contributor snapshot]'

run_id="${$}-$(date +%s)"
scratch_container="prts-sanitize-scratch-$run_id"
fresh_container="prts-sanitize-fresh-$run_id"
verify_container="prts-sanitize-verify-$run_id"
work_dir="$output_dir/work"
# The three MySQL datadirs live in Docker named volumes rather than under
# OUTPUT_DIR. Two reasons. A datadir on a case-insensitive filesystem is stamped
# lower_case_table_names=2, and since MediaWiki ships mixed-case table names
# (akFlowThread) the resulting backup cannot start on a normal Linux host at all;
# a volume is backed by the Linux VM's own filesystem, which is always case
# sensitive. And on Docker Desktop a bind-mounted datadir goes through a
# userspace file-sharing layer that makes a multi-gigabyte InnoDB restore
# unbearably slow. Only the release directory, which holds inert .zst files, is
# bind mounted.
scratch_vol="prts-sanitize-scratch-$run_id"
fresh_vol="prts-sanitize-fresh-$run_id"
verify_vol="prts-sanitize-verify-$run_id"
release_dir="$output_dir/xtrabackup"
runtime_key="$work_dir/encryption-key"
dump_file="$work_dir/ak-sanitized.sql.zst"
release_tar="$output_dir/prts-ak-sanitized.xtrabackup.tar"

cleanup() {
    docker rm -f "$scratch_container" "$fresh_container" "$verify_container" >/dev/null 2>&1 || true
    if [[ ${KEEP_WORK:-0} != 1 ]]; then
        docker volume rm "$scratch_vol" "$fresh_vol" "$verify_vol" >/dev/null 2>&1 || true
    fi
    rm -f "$runtime_key"
}
trap cleanup EXIT INT TERM

mkdir -p "$work_dir" "$release_dir"
for vol in "$scratch_vol" "$fresh_vol" "$verify_vol"; do
    docker volume create "$vol" >/dev/null || die "could not create docker volume $vol"
done

umask 077
tr -d '\r\n' < "$key_file" > "$runtime_key"
runtime_key_size=$(wc -c < "$runtime_key")
[[ $runtime_key_size -eq 32 ]] ||
    die "AES256 key must contain exactly 32 bytes after removing CR/LF; got $runtime_key_size"
chmod 600 "$runtime_key"
umask 022

# ---------------------------------------------------------------------------
# Helpers bound to the currently running container
# ---------------------------------------------------------------------------

active_container=""
existing_tables=""
# The scratch instance runs with --skip-grant-tables and takes no credentials; the
# rebuilt and verification instances do. Helpers below go through mysql_in, so the
# credentials have to travel with the container rather than be repeated per call.
active_creds=()

mysql_in() {
    # The +"${...}" guard keeps an empty credentials array from tripping set -u
    # on bash releases older than 4.4.
    docker exec -i "$active_container" mysql --binary-mode --default-character-set=binary \
        ${active_creds[@]+"${active_creds[@]}"} "$@"
}

use_container() {
    active_container=$1
    shift
    active_creds=("$@")
}

wait_for_mysql() {
    local container=$1 i
    shift
    for i in $(seq 1 180); do
        if docker exec "$container" mysqladmin ping "$@" --silent >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    docker logs --tail 100 "$container" >&2
    die "MySQL in $container did not become ready"
}

load_table_inventory() {
    existing_tables=$(mysql_in -N -e \
        "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE';")
    [[ -n $existing_tables ]] || die "no tables found in schema $DB_NAME"
}

table_exists() {
    grep -qxF "${PFX}$1" <<<"$existing_tables"
}

column_exists() {
    local n
    n=$(mysql_in -N -e "SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema='$DB_NAME' AND table_name='${PFX}$1' AND column_name='$2';")
    [[ $n -gt 0 ]]
}

# Fails the build unless every audit row reports zero. Written as an explicit
# loop rather than an awk exit code so that the offending check is named.
assert_audit_all_zero() {
    local output=$1 label=$2 name value bad=0
    while IFS=$'\t' read -r name value; do
        [[ -z $name ]] && continue
        if [[ $value != 0 ]]; then
            echo "AUDIT FAILURE [$label]: $name = $value" >&2
            bad=1
        fi
    done <<<"$output"
    [[ $bad -eq 0 ]] || die "$label sanitization audit failed"
}

# ---------------------------------------------------------------------------
# 1-2. Reassemble, decrypt, decompress, prepare
# ---------------------------------------------------------------------------

log "Reconstructing xbstream from $chunk_count downloaded xbstream entries"
while IFS= read -r -d '' chunk; do
    cat -- "$chunk"
done < <(find "$backup_dir" -type f -print0 | sort -z) |
    docker run --rm -i --user 0:0 \
        -v "$scratch_vol:/backup" \
        percona/percona-xtrabackup:8.0 \
        xbstream -x -C /backup

docker run --rm -v "$scratch_vol:/backup:ro" alpine:3.20 \
    test -f /backup/xtrabackup_checkpoints ||
    die "xbstream extraction did not produce xtrabackup_checkpoints"

log "Decrypting AES256 files and decompressing Zstandard files"
docker run --rm --user 0:0 \
    -v "$scratch_vol:/backup" \
    -v "$runtime_key:/run/secrets/dbBackupKey:ro" \
    percona/percona-xtrabackup:8.0 \
    xtrabackup --decrypt=AES256 \
        --encrypt-key-file=/run/secrets/dbBackupKey \
        --decompress --remove-original --parallel=4 \
        --target-dir=/backup

log "Preparing source XtraBackup"
docker run --rm --user 0:0 \
    -v "$scratch_vol:/backup" \
    percona/percona-xtrabackup:8.0 \
    xtrabackup --prepare --target-dir=/backup
rm -f "$runtime_key"

# ---------------------------------------------------------------------------
# 3. Production binary logs. The upstream backup ships binlog.NNNNNN and
#    binlog.index; a binlog holds the full row-level change stream and would
#    reproduce every value this script is about to redact.
# ---------------------------------------------------------------------------
log "Removing production binary logs from the prepared datadir"
docker run --rm --user 0:0 -v "$scratch_vol:/data" alpine:3.20 \
    sh -c 'rm -f /data/binlog.* /data/*-bin.* /data/*-bin /data/relay-log.* 2>/dev/null; true'

docker run --rm -v "$scratch_vol:/data" alpine:3.20 chown -R 999:999 /data

# ---------------------------------------------------------------------------
# 4. Scratch instance. --skip-grant-tables removes the need for the production
#    root password and simultaneously makes every production account unusable.
# ---------------------------------------------------------------------------
log "Starting isolated scratch MySQL container"
docker run -d --name "$scratch_container" \
    -v "$scratch_vol:/var/lib/mysql" \
    mysql:8.0.43 \
    --skip-grant-tables --skip-networking \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_bin \
    --lower-case-table-names=0 \
    --skip-log-bin >/dev/null

wait_for_mysql "$scratch_container"
use_container "$scratch_container"
load_table_inventory

# ---------------------------------------------------------------------------
# 5. Sanitize
# ---------------------------------------------------------------------------
log "Sanitizing $DB_NAME"
emit_sanitize_sql | mysql_in "$DB_NAME"

log "Reporting tables classified by neither the truncate list nor the safe list"
{
    printf 'table\tapprox_rows\n'
    # TABLE_ROWS is an InnoDB estimate; exact counts here would mean one full scan
    # per table and this report only needs to flag what deserves a look.
    mysql_in -N -e "SELECT table_name, IFNULL(table_rows, 0) FROM information_schema.tables
        WHERE table_schema='$DB_NAME' AND table_type='BASE TABLE' ORDER BY table_name;" |
    while IFS=$'\t' read -r full rows; do
        [[ -z $full ]] && continue
        bare=${full#"$PFX"}
        grep -qxF "$bare" <<<"$TRUNCATE_TABLES" && continue
        grep -qwF "$bare" <<<"$KNOWN_SAFE_TABLES" && continue
        case "$bare" in
            smw_*|cargo_*) continue ;;
        esac
        printf '%s\t%s\n' "$full" "$rows"
    done
} | tee "$output_dir/unclassified-tables.tsv"

# ---------------------------------------------------------------------------
# 6. Pre-dump audit
# ---------------------------------------------------------------------------
log "Running pre-dump sanitization audit"
audit_output=$(emit_audit_sql 0 | mysql_in -N "$DB_NAME")
printf '%s\n' "$audit_output" | tee "$output_dir/audit-pre-dump.tsv"
assert_audit_all_zero "$audit_output" "pre-dump"

# ---------------------------------------------------------------------------
# 7. Logical dump, then destroy the scratch datadir. Everything after this point
#    is built from the dump, so no page that ever held an unredacted byte can
#    reach the artifact.
# ---------------------------------------------------------------------------
# Views are captured separately and recreated on the new server. Two reasons.
# A view carries a DEFINER, and this schema's dpl_clview names akroot@172.22%,
# so the view definition leaks a production account and an internal network
# range into the artifact. And because that account no longer exists the view is
# already unusable: mysqldump aborts on it with error 1356 rather than skipping
# it. Reading information_schema.views works regardless, so capture the bodies,
# exclude the views from the dump, and rebuild them after the import with
# SQL SECURITY INVOKER, which needs no definer to resolve.
view_names=$(mysql_in -N "$DB_NAME" -e \
    "SELECT table_name FROM information_schema.views WHERE table_schema='$DB_NAME';")

view_stmts=""
dump_ignore=()
if [[ -n $view_names ]]; then
    view_stmts=$(mysql_in -N --raw "$DB_NAME" <<SQL
SELECT CONCAT('DROP VIEW IF EXISTS \`', table_name, '\`; ',
              'CREATE SQL SECURITY INVOKER VIEW \`', table_name, '\` AS ',
              view_definition, ';')
FROM information_schema.views WHERE table_schema = '$DB_NAME';
SQL
)
    while IFS= read -r v; do
        [[ -z $v ]] && continue
        dump_ignore+=(--ignore-table="$DB_NAME.$v")
        log "View captured for rebuild: $v"
    done <<<"$view_names"
fi

log "Dumping sanitized $DB_NAME"
docker exec "$scratch_container" mysqldump \
    --single-transaction --quick --no-tablespaces --skip-lock-tables \
    --skip-add-locks --disable-keys --set-gtid-purged=OFF \
    --routines --events --triggers \
    --default-character-set=binary \
    ${dump_ignore[@]+"${dump_ignore[@]}"} \
    --databases "$DB_NAME" |
    LC_ALL=C sed -E 's/DEFINER=`[^`]*`@`[^`]*`//g' |
    zstd -q -T0 -3 -o "$dump_file"

[[ -s $dump_file ]] || die "logical dump is empty"
log "Dump written: $(du -h "$dump_file" | cut -f1)"

docker stop "$scratch_container" >/dev/null
docker rm "$scratch_container" >/dev/null
[[ ${KEEP_WORK:-0} == 1 ]] || docker volume rm "$scratch_vol" >/dev/null

# ---------------------------------------------------------------------------
# 8. Brand-new server. Its mysql.ibd, ibdata1 and undo tablespaces are created
#    from nothing by the entrypoint, so they contain no production account
#    hashes and no superseded row versions.
# ---------------------------------------------------------------------------
log "Initializing a brand-new MySQL server and importing the sanitized dump"
docker run -d --name "$fresh_container" \
    -e MYSQL_ROOT_PASSWORD=akdev \
    -v "$fresh_vol:/var/lib/mysql" \
    mysql:8.0.43 \
    --default-authentication-plugin=mysql_native_password \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_bin \
    --skip-log-bin \
    --lower-case-table-names=0 \
    --innodb-buffer-pool-size="${INNODB_BUFFER_POOL:-2G}" \
    --innodb-flush-log-at-trx-commit=0 >/dev/null

# The official entrypoint runs a temporary server during initialization that also
# answers on the socket, so a plain ping can succeed against the wrong instance.
# The real startup is the only one that reports a TCP port.
log "Waiting for the new server to finish initializing"
for _ in $(seq 1 300); do
    docker logs "$fresh_container" 2>&1 | grep -q "ready for connections.*port: 3306" && break
    sleep 2
done
docker logs "$fresh_container" 2>&1 | grep -q "ready for connections.*port: 3306" || {
    docker logs --tail 100 "$fresh_container" >&2
    die "new MySQL server did not finish initializing"
}
wait_for_mysql "$fresh_container" -uroot -pakdev
use_container "$fresh_container" -uroot -pakdev

{
    printf 'SET SESSION sql_log_bin = 0;\nSET SESSION foreign_key_checks = 0;\nSET SESSION unique_checks = 0;\n'
    zstd -dc "$dump_file"
} | mysql_in

if [[ -n $view_stmts ]]; then
    log "Rebuilding views with SQL SECURITY INVOKER"
    printf '%s\n' "$view_stmts" | mysql_in "$DB_NAME"
    while IFS= read -r v; do
        [[ -z $v ]] && continue
        mysql_in -N "$DB_NAME" -e "SELECT 1 FROM \`$v\` LIMIT 1;" >/dev/null ||
            die "view $v is still not queryable after rebuild"
        log "View rebuilt and queryable: $v"
    done <<<"$view_names"
fi

log "Creating contributor accounts"
emit_create_accounts_sql | mysql_in

load_table_inventory

log "Running post-import audit"
import_audit=$(emit_audit_sql 1 | mysql_in -N "$DB_NAME")
printf '%s\n' "$import_audit" | tee "$output_dir/audit-post-import.tsv"
assert_audit_all_zero "$import_audit" "post-import"

# ---------------------------------------------------------------------------
# 9. Back up the new server
# ---------------------------------------------------------------------------
log "Creating sanitized compressed full XtraBackup"
docker run --rm --user 0:0 --network "container:$fresh_container" \
    -v "$fresh_vol:/var/lib/mysql:ro" \
    -v "$release_dir:/backup" \
    percona/percona-xtrabackup:8.0 \
    xtrabackup --backup --host=127.0.0.1 --port=3306 \
        --user=akdev --password=akdev \
        --target-dir=/backup --compress \
        --compress-threads=4 --parallel=4

docker stop "$fresh_container" >/dev/null
docker rm "$fresh_container" >/dev/null
[[ ${KEEP_WORK:-0} == 1 ]] || docker volume rm "$fresh_vol" >/dev/null

docker run --rm -v "$release_dir:/data" alpine:3.20 \
    sh -c 'chown -R 1000:1000 /data && chmod -R u=rwX,go=rX /data'

# ---------------------------------------------------------------------------
# 10. Restore verification, then the byte scan that the previous revision lacked
# ---------------------------------------------------------------------------
log "Preparing an independent restore verification copy"
docker run --rm -v "$release_dir:/src:ro" -v "$verify_vol:/dst" alpine:3.20 \
    cp -a /src/. /dst/
docker run --rm --user 0:0 \
    -v "$verify_vol:/backup" \
    percona/percona-xtrabackup:8.0 \
    xtrabackup --decompress --remove-original --target-dir=/backup --parallel=4
docker run --rm --user 0:0 \
    -v "$verify_vol:/backup" \
    percona/percona-xtrabackup:8.0 \
    xtrabackup --prepare --target-dir=/backup

log "Scanning the decompressed artifact for surviving plaintext"
# An SQL audit only ever sees live rows. This reads the raw tablespace bytes,
# which is what a recipient holding the tar can do. Wiki article text may
# legitimately contain an e-mail address, so the e-mail scan targets only the
# tablespaces that must never hold one.
bytescan=$(docker run --rm -v "$verify_vol:/data:ro" alpine:3.20 sh -c '
    EMAIL="[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,10}"
    scan_email() {
        [ -f "$1" ] || { echo "0"; return; }
        LC_ALL=C grep -aoE "$EMAIL" "$1" 2>/dev/null | sort -u | wc -l | tr -d " "
    }
    scan_lit() {
        [ -f "$1" ] || { echo "0"; return; }
        LC_ALL=C grep -aoF "$2" "$1" 2>/dev/null | wc -l | tr -d " "
    }
    # akpage is deliberately not scanned for e-mail addresses. Because this
    # instance was rebuilt from a logical dump, every byte in a tablespace came
    # from an INSERT of a live row; there is no stale free space left to search.
    # An address-shaped string in akpage can therefore only be a live page
    # title, and titles are public wiki content: File: pages here carry uploader
    # attributions such as "..._by_bilibili@Bio-Hazard.png", which matches the
    # pattern exactly and is not personal data. The tablespaces kept below are
    # ones where an address-shaped string always means the sanitization failed.
    printf "email_in_user_tablespace\t%s\n"   "$(scan_email /data/ak/akuser.ibd)"
    printf "email_in_actor_tablespace\t%s\n"  "$(scan_email /data/ak/akactor.ibd)"
    printf "email_in_comment_tablespace\t%s\n" "$(scan_email /data/ak/akcomment.ibd)"
    printf "email_in_mysql_schema\t%s\n"      "$(scan_email /data/mysql.ibd)"
    printf "email_in_system_tablespace\t%s\n" "$(scan_email /data/ibdata1)"
    total=0
    for u in /data/undo_*; do
        [ -f "$u" ] || continue
        n=$(scan_email "$u"); total=$((total + n))
    done
    printf "email_in_undo_tablespaces\t%s\n" "$total"
    for acct in akroot fgoroot mooncellroot exporter debian-sys-maint; do
        printf "production_account_%s\t%s\n" "$acct" "$(scan_lit /data/mysql.ibd "$acct")"
    done
    for sch in mooncell my_wiki; do
        printf "dropped_schema_%s\t%s\n" "$sch" "$(scan_lit /data/mysql.ibd "$sch")"
    done
    printf "binlog_files_present\t%s\n" "$(ls /data/binlog.* /data/*-bin.* 2>/dev/null | wc -l | tr -d " ")"
')
printf '%s\n' "$bytescan" | tee "$output_dir/bytescan.tsv"
assert_audit_all_zero "$bytescan" "byte-scan"

docker run --rm -v "$verify_vol:/data" alpine:3.20 chown -R 999:999 /data

log "Starting independently restored MySQL container"
docker run -d --name "$verify_container" \
    -v "$verify_vol:/var/lib/mysql" \
    mysql:8.0.43 \
    --default-authentication-plugin=mysql_native_password \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_bin \
    --lower-case-table-names=0 \
    --skip-log-bin >/dev/null

wait_for_mysql "$verify_container" -uroot -pakdev
use_container "$verify_container" -uroot -pakdev
load_table_inventory

log "Running post-restore audit and table checks"
verify_audit=$(emit_audit_sql 1 | mysql_in -N "$DB_NAME")
printf '%s\n' "$verify_audit" | tee "$output_dir/audit-post-restore.tsv"
assert_audit_all_zero "$verify_audit" "post-restore"

check_output=$(docker exec "$verify_container" mysql -N -uroot -pakdev -e "
CHECK TABLE
    ${DB_NAME}.${PFX}user,
    ${DB_NAME}.${PFX}actor,
    ${DB_NAME}.${PFX}revision,
    ${DB_NAME}.${PFX}page,
    ${DB_NAME}.${PFX}text,
    ${DB_NAME}.${PFX}comment,
    ${DB_NAME}.${PFX}content,
    ${DB_NAME}.${PFX}slots,
    ${DB_NAME}.${PFX}pagelinks,
    ${DB_NAME}.${PFX}searchindex
QUICK;
")
printf '%s\n' "$check_output" | tee "$output_dir/check-table.tsv"

# Running the check and ignoring its verdict is worse than not running it. The
# third column is Msg_type; anything reported as an error fails the build.
if printf '%s\n' "$check_output" | awk -F '\t' '$3 == "error" { found = 1 } END { exit !found }'; then
    die "CHECK TABLE reported an error; see $output_dir/check-table.tsv"
fi

docker stop "$verify_container" >/dev/null
docker rm "$verify_container" >/dev/null

# ---------------------------------------------------------------------------
# 11. Package
# ---------------------------------------------------------------------------
log "Packaging release"
tar -cf "$release_tar" -C "$release_dir" .
(
    cd "$output_dir"
    sha256sum "$(basename "$release_tar")" > SHA256SUMS
)

if [[ ${KEEP_WORK:-0} != 1 ]]; then
    log "Removing temporary prepared datadirs and the logical dump"
    docker volume rm "$verify_vol" >/dev/null 2>&1 || true
    rm -f "$dump_file"
    rmdir "$work_dir" 2>/dev/null || true
fi

log "Completed"
echo "Release directory: $release_dir"
echo "Release tar:       $release_tar"
echo "Checksum file:     $output_dir/SHA256SUMS"
cat "$output_dir/SHA256SUMS"
echo
echo "Contributors restore with:  xtrabackup --decompress --prepare"
echo "Dev credentials:            root/akdev and akdev/akdev"
echo
echo "Review $output_dir/unclassified-tables.tsv before publishing: it lists tables"
echo "that neither the truncate list nor the known-safe list accounts for."
