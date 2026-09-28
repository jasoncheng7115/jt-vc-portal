#!/bin/bash
# 建立 / 更新 jt-vc-portal 用的 Keycloak realm（可重複執行）。說明見 KEYCLOAK-SETUP.md。
#
# 做的事：
#   0. master realm frontendUrl（設了 KC_ADMIN_URL 時）：管理員登入只走內網管理網址
#   1. realm（多語系 LOCALES / DEFAULT_LOCALE、sslRequired=external、暴力破解偵測、事件記錄、OTP 政策、預設要求設定 OTP）
#   2. LDAP 使用者聯合（AD，LDAPS、唯讀、只納入指定群組成員）+ 群組對應
#   3. OIDC client「jt-vc-portal」（confidential、授權碼流程 + PKCE S256、固定 redirect URI）
#      + groups claim mapper（不含完整路徑）
#
# 用法（在 Keycloak 主機、compose 目錄下）：
#   cp realm.env.example realm.env && vi realm.env && chmod 600 realm.env
#   ./configure-realm.sh
# 需要環境變數 KC_ADMIN_USER / KC_ADMIN_PASSWORD（master realm 管理員），可放在 realm.env。
set -euo pipefail
cd "$(dirname "$0")"
[ -f realm.env ] || { echo "realm.env not found (copy realm.env.example)"; exit 1; }
# realm.env 中留空的 KC_ADMIN_USER / KC_ADMIN_PASSWORD 不覆蓋已 export 的值（可不把管理員密碼寫進檔案）
_KCU=${KC_ADMIN_USER:-}; _KCP=${KC_ADMIN_PASSWORD:-}
set -a; . ./realm.env; set +a
KC_ADMIN_USER=${KC_ADMIN_USER:-$_KCU}; KC_ADMIN_PASSWORD=${KC_ADMIN_PASSWORD:-$_KCP}

: "${REALM:?}" "${PORTAL_URL:?}" "${CLIENT_ID:?}" "${ADMIN_GROUP:?}" "${HOST_GROUP:?}" "${KC_ADMIN_USER:?}" "${KC_ADMIN_PASSWORD:?}"
# LDAP_URL 留空＝不設定 AD 聯合（僅用 Keycloak 本地帳號；自動化測試也用此模式）
LDAP_URL=${LDAP_URL:-}
if [ -n "$LDAP_URL" ]; then : "${LDAP_BASE_DN:?}" "${LDAP_BIND_DN:?}" "${LDAP_GROUPS_DN:?}"; fi
LOCKOUT_FAILURES=${LOCKOUT_FAILURES:-5}
# 介面語言（登入頁 / 帳號頁 / 管理介面）：依瀏覽器語言自動選擇，DEFAULT_LOCALE 為找不到對應時的預設
LOCALES=${LOCALES:-en,zh-Hant,ja}
DEFAULT_LOCALE=${DEFAULT_LOCALE:-en}
LOCALES_JSON="[\"$(echo "$LOCALES" | sed 's/ //g; s/,/","/g')\"]"
LDAP_BIND_CREDENTIAL=${LDAP_BIND_CREDENTIAL:-}

KC=${KCADM:-docker compose exec -T keycloak /opt/keycloak/bin/kcadm.sh}
$KC config credentials --server "${KC_SERVER:-http://localhost:8080}" --realm master --user "$KC_ADMIN_USER" --password "$KC_ADMIN_PASSWORD" >/dev/null

# ---------- 0. master realm：管理員登入頁走內網管理網址 ----------
# 否則 master 登入頁會用對外網址（KC_HOSTNAME），管理介面在對外網址未上線 / 反代拒絕 master 時卡住。
if [ -n "${KC_ADMIN_URL:-}" ]; then
  $KC update realms/master -s "attributes.frontendUrl=$KC_ADMIN_URL" >/dev/null
  echo "master realm frontendUrl = $KC_ADMIN_URL"
  # 改了 frontendUrl 後，先前取得的管理 token（issuer 不同）會被拒（401），需重新登入
  $KC config credentials --server "${KC_SERVER:-http://localhost:8080}" --realm master --user "$KC_ADMIN_USER" --password "$KC_ADMIN_PASSWORD" >/dev/null
fi

# master realm（管理介面）也套用多語系
$KC update realms/master -s internationalizationEnabled=true -s "supportedLocales=$LOCALES_JSON" -s "defaultLocale=$DEFAULT_LOCALE" >/dev/null

# ---------- 1. realm ----------
if ! $KC get "realms/$REALM" >/dev/null 2>&1; then
  $KC create realms -s realm="$REALM" -s enabled=true >/dev/null
  echo "created realm $REALM"
fi
$KC update "realms/$REALM" \
  -s sslRequired=external \
  -s registrationAllowed=false -s resetPasswordAllowed=false -s editUsernameAllowed=false \
  -s rememberMe=false -s loginWithEmailAllowed=false -s duplicateEmailsAllowed=true \
  -s bruteForceProtected=true -s permanentLockout=false \
  -s failureFactor="$LOCKOUT_FAILURES" -s waitIncrementSeconds=300 -s maxFailureWaitSeconds=1800 \
  -s maxDeltaTimeSeconds=900 -s minimumQuickLoginWaitSeconds=60 -s quickLoginCheckMilliSeconds=1000 \
  -s eventsEnabled=true -s eventsExpiration=7776000 -s 'enabledEventTypes=["LOGIN","LOGIN_ERROR","LOGOUT","LOGOUT_ERROR","CODE_TO_TOKEN","CODE_TO_TOKEN_ERROR","UPDATE_TOTP","REMOVE_TOTP"]' \
  -s adminEventsEnabled=true -s adminEventsDetailsEnabled=false \
  -s 'eventsListeners=["jboss-logging"]' \
  -s otpPolicyType=totp -s otpPolicyAlgorithm=HmacSHA1 -s otpPolicyDigits=6 -s otpPolicyPeriod=30 \
  -s ssoSessionIdleTimeout=1800 -s ssoSessionMaxLifespan=43200 \
  -s accessTokenLifespan=300 \
  -s internationalizationEnabled=true -s "supportedLocales=$LOCALES_JSON" -s "defaultLocale=$DEFAULT_LOCALE" >/dev/null
# 所有使用者首次登入都必須設定 OTP（之後每次登入都要驗證碼）
$KC update "authentication/required-actions/CONFIGURE_TOTP" -r "$REALM" -s enabled=true -s defaultAction=true >/dev/null
echo "realm settings applied (brute force: ${LOCKOUT_FAILURES} failures, OTP required, locales: ${LOCALES}, default ${DEFAULT_LOCALE})"

# ---------- 2. LDAP（AD）聯合 ----------
if [ -n "$LDAP_URL" ]; then
REALM_ID=$($KC get "realms/$REALM" --fields id --format csv --noquotes)
LDAP_ID=$($KC get components -r "$REALM" -q name=ad -q type=org.keycloak.storage.UserStorageProvider --fields id --format csv --noquotes 2>/dev/null | head -1 || true)
FILTER="(|(memberOf=CN=${ADMIN_GROUP},${LDAP_GROUPS_DN})(memberOf=CN=${HOST_GROUP},${LDAP_GROUPS_DN}))"
LDAP_ARGS=(
  -s name=ad -s providerId=ldap -s providerType=org.keycloak.storage.UserStorageProvider -s parentId="$REALM_ID"
  -s 'config.enabled=["true"]' -s 'config.priority=["0"]'
  -s 'config.vendor=["ad"]' -s 'config.editMode=["READ_ONLY"]' -s 'config.importEnabled=["true"]'
  -s 'config.syncRegistrations=["false"]'
  -s "config.connectionUrl=[\"${LDAP_URL}\"]" -s 'config.useTruststoreSpi=["always"]' -s 'config.startTls=["false"]'
  -s 'config.connectionPooling=["true"]' -s 'config.connectionTimeout=["5000"]' -s 'config.readTimeout=["10000"]'
  -s 'config.authType=["simple"]' -s "config.bindDn=[\"${LDAP_BIND_DN}\"]"
  -s "config.usersDn=[\"${LDAP_BASE_DN}\"]" -s 'config.searchScope=["2"]' -s 'config.pagination=["true"]'
  -s 'config.usernameLDAPAttribute=["sAMAccountName"]' -s 'config.rdnLDAPAttribute=["cn"]'
  -s 'config.uuidLDAPAttribute=["objectGUID"]' -s 'config.userObjectClasses=["person, organizationalPerson, user"]'
  -s "config.customUserSearchFilter=[\"${FILTER}\"]"
  -s 'config.trustEmail=["false"]' -s 'config.validatePasswordPolicy=["false"]'
  -s 'config.allowKerberosAuthentication=["false"]' -s 'config.useKerberosForPasswordAuthentication=["false"]'
  -s 'config.fullSyncPeriod=["-1"]' -s 'config.changedSyncPeriod=["-1"]' -s 'config.cachePolicy=["DEFAULT"]'
)
[ -n "$LDAP_BIND_CREDENTIAL" ] && LDAP_ARGS+=(-s "config.bindCredential=[\"${LDAP_BIND_CREDENTIAL}\"]")
if [ -z "$LDAP_ID" ]; then
  LDAP_ID=$($KC create components -r "$REALM" "${LDAP_ARGS[@]}" -i)
  echo "created LDAP provider ad ($LDAP_ID)"
else
  $KC update "components/$LDAP_ID" -r "$REALM" "${LDAP_ARGS[@]}" >/dev/null
  echo "updated LDAP provider ad ($LDAP_ID)"
fi
[ -z "$LDAP_BIND_CREDENTIAL" ] && echo "  NOTE: LDAP bind password not set — set it in the admin console (User federation → ad) or LDAP_BIND_CREDENTIAL"

GM_ID=$($KC get components -r "$REALM" -q parent="$LDAP_ID" -q name=vc-groups --fields id --format csv --noquotes 2>/dev/null | head -1 || true)
GM_ARGS=(
  -s name=vc-groups -s providerId=group-ldap-mapper -s providerType=org.keycloak.storage.ldap.mappers.LDAPStorageMapper -s parentId="$LDAP_ID"
  -s "config.\"groups.dn\"=[\"${LDAP_GROUPS_DN}\"]" -s 'config."group.name.ldap.attribute"=["cn"]'
  -s 'config."group.object.classes"=["group"]' -s 'config."preserve.group.inheritance"=["false"]'
  -s 'config."ignore.missing.groups"=["true"]' -s 'config."membership.ldap.attribute"=["member"]'
  -s 'config."membership.attribute.type"=["DN"]' -s 'config."membership.user.ldap.attribute"=["sAMAccountName"]'
  -s "config.\"groups.ldap.filter\"=[\"(|(cn=${ADMIN_GROUP})(cn=${HOST_GROUP}))\"]"
  -s 'config.mode=["READ_ONLY"]' -s 'config."user.roles.retrieve.strategy"=["LOAD_GROUPS_BY_MEMBER_ATTRIBUTE"]'
  -s 'config."drop.non.existing.groups.during.sync"=["false"]'
)
if [ -z "$GM_ID" ]; then $KC create components -r "$REALM" "${GM_ARGS[@]}" >/dev/null; echo "created group mapper vc-groups";
else $KC update "components/$GM_ID" -r "$REALM" "${GM_ARGS[@]}" >/dev/null; echo "updated group mapper vc-groups"; fi
else
  echo "LDAP_URL empty — skipping AD federation (Keycloak local users only)"
  for g in "$ADMIN_GROUP" "$HOST_GROUP"; do
    $KC get groups -r "$REALM" -q search="$g" --fields name --format csv --noquotes 2>/dev/null | grep -qx "$g" || { $KC create groups -r "$REALM" -s name="$g" >/dev/null; echo "created local group $g"; }
  done
fi

# ---------- 3. OIDC client ----------
CID=$($KC get clients -r "$REALM" -q clientId="$CLIENT_ID" --fields id --format csv --noquotes 2>/dev/null | head -1 || true)
PORTAL_URL=${PORTAL_URL%/}
CL_ARGS=(
  -s clientId="$CLIENT_ID" -s name="jt-vc-portal" -s enabled=true -s protocol=openid-connect
  -s publicClient=false -s clientAuthenticatorType=client-secret
  -s standardFlowEnabled=true -s implicitFlowEnabled=false -s directAccessGrantsEnabled=false
  -s serviceAccountsEnabled=false -s consentRequired=false -s frontchannelLogout=false
  -s "redirectUris=[\"${PORTAL_URL}/sso-callback\"]" -s 'webOrigins=[]' -s "rootUrl=${PORTAL_URL}"
  -s 'attributes."pkce.code.challenge.method"=S256'
  -s "attributes.\"post.logout.redirect.uris\"=${PORTAL_URL}/"
)
if [ -z "$CID" ]; then CID=$($KC create clients -r "$REALM" "${CL_ARGS[@]}" -i); echo "created client $CLIENT_ID";
else $KC update "clients/$CID" -r "$REALM" "${CL_ARGS[@]}" >/dev/null; echo "updated client $CLIENT_ID"; fi

MID=$($KC get "clients/$CID/protocol-mappers/models" -r "$REALM" --fields id,name --format csv --noquotes 2>/dev/null | awk -F, '$2=="groups"{print $1}' | head -1 || true)
MAP_ARGS=(-s name=groups -s protocol=openid-connect -s protocolMapper=oidc-group-membership-mapper
  -s 'config."claim.name"=groups' -s 'config."full.path"=false'
  -s 'config."id.token.claim"=true' -s 'config."access.token.claim"=false' -s 'config."userinfo.token.claim"=true')
if [ -z "$MID" ]; then $KC create "clients/$CID/protocol-mappers/models" -r "$REALM" "${MAP_ARGS[@]}" >/dev/null; echo "created groups mapper";
else $KC update "clients/$CID/protocol-mappers/models/$MID" -r "$REALM" "${MAP_ARGS[@]}" >/dev/null; echo "updated groups mapper"; fi

SECRET=$($KC get "clients/$CID/client-secret" -r "$REALM" --fields value --format csv --noquotes)
echo
echo "== jt-vc-portal settings (System settings → Single sign-on) =="
echo "Issuer        : ${KC_ISSUER_BASE:-<KC_PUBLIC_URL>}/realms/${REALM}"
echo "Client ID     : ${CLIENT_ID}"
echo "Client secret : (printed to client-secret.txt, chmod 600)"
umask 077; echo "$SECRET" > "${SECRET_OUT:-client-secret.txt}"
echo "Groups claim  : groups   Admin group: ${ADMIN_GROUP}   Host group: ${HOST_GROUP}"
echo "Redirect URI  : ${PORTAL_URL}/sso-callback"
