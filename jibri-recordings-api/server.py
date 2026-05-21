#!/usr/bin/env python3
# 錄影調閱 API：只服務 jt-vc-portal（Bearer token + 來源 IP 允許清單）。
# 提供：列表 / 串流播放(Range) / 下載 / 容量統計 / 刪除 / 保留政策設定與自動清理。
# 設定與清理紀錄存於 REC_DIR 下的點開頭檔（list 只掃資料夾，故不影響列表）。
import os, json, re, hmac, time, shutil, threading, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

REC_DIR  = os.environ.get("REC_DIR", "/srv/recordings")
TOKEN    = os.environ.get("API_TOKEN", "")
ALLOW    = set(x.strip() for x in os.environ.get("ALLOW_IPS", "").split(",") if x.strip())
PORT     = int(os.environ.get("PORT", "9080"))
CONF_FILE   = os.path.join(REC_DIR, ".api-config.json")
CLEAN_LOG   = os.path.join(REC_DIR, ".api-cleanup.log")
CLEAN_EVERY = int(os.environ.get("CLEAN_INTERVAL", "3600"))   # 自動清理間隔（秒）

RECORDING_GRACE = 300          # mp4 在 5 分鐘內仍被寫入 → 視為錄製中，永不清理
MIN_OK_SIZE     = 256 * 1024   # 小於 256KB 視為未完成（會議瞬間結束殘留）
GB = 1024 ** 3

DEFAULT_CONF = {
    "time_enabled": False, "time_days": 30,
    "cap_enabled": False, "cap_mode": "min_free_gb", "cap_value_gb": 10,
    "orphan_auto": False, "orphan_age_hours": 24,
}

# ---------- 設定 ----------
def load_conf():
    c = dict(DEFAULT_CONF)
    try:
        with open(CONF_FILE) as f:
            c.update({k: v for k, v in json.load(f).items() if k in DEFAULT_CONF})
    except Exception:
        pass
    return c

def save_conf(body):
    c = load_conf()
    c["time_enabled"]   = bool(body.get("time_enabled", c["time_enabled"]))
    c["time_days"]      = max(1, int(body.get("time_days", c["time_days"])))
    c["cap_enabled"]    = bool(body.get("cap_enabled", c["cap_enabled"]))
    c["cap_mode"]       = body.get("cap_mode", c["cap_mode"]) if body.get("cap_mode") in ("min_free_gb", "max_used_gb") else c["cap_mode"]
    c["cap_value_gb"]   = max(1, int(body.get("cap_value_gb", c["cap_value_gb"])))
    c["orphan_auto"]    = bool(body.get("orphan_auto", c["orphan_auto"]))
    c["orphan_age_hours"] = max(1, int(body.get("orphan_age_hours", c["orphan_age_hours"])))
    with open(CONF_FILE, "w") as f:
        json.dump(c, f, ensure_ascii=False, indent=2)
    return c

def clean_log(entry):
    entry["ts"] = int(time.time())
    try:
        with open(CLEAN_LOG, "a") as f:
            f.write(json.dumps(entry, ensure_ascii=False) + "\n")
    except Exception:
        pass

def tail_log(n=200):
    if not os.path.isfile(CLEAN_LOG):
        return []
    try:
        lines = open(CLEAN_LOG, encoding="utf-8").read().splitlines()[-n:]
        return [json.loads(x) for x in lines if x.strip()]
    except Exception:
        return []

# ---------- 掃描 ----------
def _dir_size(d):
    s = 0
    for f in os.listdir(d):
        p = os.path.join(d, f)
        if os.path.isfile(p):
            s += os.path.getsize(p)
    return s

def _room_of(d, mp4):
    meta = os.path.join(d, "metadata.json")
    if os.path.isfile(meta):
        try:
            url = (json.load(open(meta)).get("meeting_url") or "").rstrip("/")
            if url:
                return urllib.parse.unquote(url.split("/")[-1])
        except Exception:
            pass
    if mp4:
        return re.sub(r"_\d{4}-\d{2}-\d{2}.*$", "", mp4)
    return ""

def scan():
    out = []
    if not os.path.isdir(REC_DIR):
        return out
    now = time.time()
    for rid in os.listdir(REC_DIR):
        d = os.path.join(REC_DIR, rid)
        if not os.path.isdir(d):
            continue
        mp4 = next((f for f in sorted(os.listdir(d)) if f.lower().endswith(".mp4")), None)
        if not mp4:
            # 無 mp4：會議異常結束殘留（只剩 metadata 或 .part）
            st = os.stat(d)
            out.append({"id": rid, "room": _room_of(d, None), "file": None,
                        "size": _dir_size(d), "mtime": int(st.st_mtime), "status": "orphan"})
            continue
        st = os.stat(os.path.join(d, mp4))
        age = now - st.st_mtime
        if age < RECORDING_GRACE:
            status = "recording"
        elif st.st_size < MIN_OK_SIZE:
            status = "incomplete"
        else:
            status = "ok"
        out.append({"id": rid, "room": _room_of(d, mp4), "file": mp4,
                    "size": st.st_size, "mtime": int(st.st_mtime), "status": status})
    out.sort(key=lambda x: x["mtime"], reverse=True)
    return out

def stats():
    du = shutil.disk_usage(REC_DIR if os.path.isdir(REC_DIR) else "/")
    recs = scan()
    by = {}
    for r in recs:
        by[r["status"]] = by.get(r["status"], 0) + 1
    return {
        "disk": {"total": du.total, "used": du.used, "free": du.free},
        "recordings": {"count": len(recs), "size": sum(r["size"] for r in recs), "by_status": by},
        "config": load_conf(),
    }

# ---------- 刪除 / 清理 ----------
def _safe_dir(rid):
    if not re.fullmatch(r"[A-Za-z0-9._-]+", rid or ""):
        return None
    d = os.path.realpath(os.path.join(REC_DIR, rid))
    if d != os.path.join(os.path.realpath(REC_DIR), rid) or not os.path.isdir(d):
        return None
    return d

def delete_rec(rid, reason="manual"):
    d = _safe_dir(rid)
    if not d:
        return False
    recs = {r["id"]: r for r in scan()}
    r = recs.get(rid)
    if r and r["status"] == "recording":   # 錄製中永不刪
        return False
    shutil.rmtree(d, ignore_errors=True)
    clean_log({"action": "delete", "id": rid, "room": r["room"] if r else "",
               "size": r["size"] if r else 0, "reason": reason})
    return True

def run_cleanup():
    """套用保留政策（時間 / 容量 / 殘留），預設全停用。回傳刪除清單。"""
    c = load_conf()
    removed = []
    now = time.time()
    recs = [r for r in scan() if r["status"] != "recording"]   # 錄製中排除

    if c["time_enabled"]:
        cutoff = now - c["time_days"] * 86400
        for r in list(recs):
            if r["status"] in ("ok", "incomplete") and r["mtime"] < cutoff:
                if delete_rec(r["id"], "retention_time"):
                    removed.append(r); recs.remove(r)

    if c["orphan_auto"]:
        cutoff = now - c["orphan_age_hours"] * 3600
        for r in list(recs):
            if r["status"] in ("orphan", "incomplete") and r["mtime"] < cutoff:
                if delete_rec(r["id"], "orphan_auto"):
                    removed.append(r); recs.remove(r)

    if c["cap_enabled"]:
        order = sorted(recs, key=lambda x: x["mtime"])   # 由舊到新
        if c["cap_mode"] == "max_used_gb":
            total = sum(r["size"] for r in recs)
            limit = c["cap_value_gb"] * GB
            for r in order:
                if total <= limit:
                    break
                if delete_rec(r["id"], "retention_cap_used"):
                    removed.append(r); total -= r["size"]
        else:  # min_free_gb
            limit = c["cap_value_gb"] * GB
            for r in order:
                if shutil.disk_usage(REC_DIR).free >= limit:
                    break
                if delete_rec(r["id"], "retention_cap_free"):
                    removed.append(r)
    return removed

def cleanup_loop():
    while True:
        time.sleep(CLEAN_EVERY)
        try:
            run_cleanup()
        except Exception as e:
            clean_log({"action": "error", "detail": str(e)})

# ---------- HTTP ----------
def mp4_path(rid):
    d = _safe_dir(rid)
    if not d:
        return None
    f = next((x for x in sorted(os.listdir(d)) if x.lower().endswith(".mp4")), None)
    return os.path.join(d, f) if f else None

class H(BaseHTTPRequestHandler):
    def _ok_auth(self):
        if ALLOW and self.client_address[0] not in ALLOW:
            return False
        h = self.headers.get("Authorization", "")
        return bool(TOKEN) and h.startswith("Bearer ") and hmac.compare_digest(h[7:], TOKEN)

    def _json(self, obj, code=200):
        b = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)

    def _deny(self):
        self.send_response(403); self.end_headers(); self.wfile.write(b"forbidden")

    def do_GET(self):
        if not self._ok_auth():
            return self._deny()
        u = urllib.parse.urlparse(self.path)
        if u.path == "/api/ping":
            return self._json({"ok": True, "service": "jibri-recordings-api", "version": 2})
        if u.path == "/api/stats":
            return self._json({"ok": True, **stats()})
        if u.path == "/api/config":
            return self._json({"ok": True, "config": load_conf()})
        if u.path == "/api/cleanup-log":
            return self._json({"ok": True, "log": tail_log()})
        if u.path == "/api/recordings":
            return self._json({"ok": True, "recordings": scan()})
        m = re.fullmatch(r"/api/recordings/([^/]+)/file", u.path)
        if m:
            return self._serve(urllib.parse.unquote(m.group(1)), urllib.parse.parse_qs(u.query))
        self.send_response(404); self.end_headers()

    def do_POST(self):
        if not self._ok_auth():
            return self._deny()
        u = urllib.parse.urlparse(self.path)
        body = {}
        try:
            n = int(self.headers.get("Content-Length", "0"))
            if n:
                body = json.loads(self.rfile.read(n) or b"{}")
        except Exception:
            body = {}
        if u.path == "/api/config":
            return self._json({"ok": True, "config": save_conf(body)})
        if u.path == "/api/cleanup-run":
            removed = run_cleanup()
            return self._json({"ok": True, "removed": removed})
        self.send_response(404); self.end_headers()

    def do_DELETE(self):
        if not self._ok_auth():
            return self._deny()
        u = urllib.parse.urlparse(self.path)
        m = re.fullmatch(r"/api/recordings/([^/]+)", u.path)
        if m:
            ok = delete_rec(urllib.parse.unquote(m.group(1)), "manual")
            return self._json({"ok": ok}, 200 if ok else 404)
        self.send_response(404); self.end_headers()

    def _serve(self, rid, qs):
        p = mp4_path(rid)
        if not p:
            self.send_response(404); self.end_headers(); return
        size = os.path.getsize(p); start, end, status = 0, size - 1, 200
        rng = self.headers.get("Range")
        if rng:
            mm = re.match(r"bytes=(\d+)-(\d*)", rng)
            if mm:
                start = int(mm.group(1)); end = int(mm.group(2)) if mm.group(2) else size - 1
                end = min(end, size - 1); status = 206
        length = end - start + 1
        self.send_response(status)
        self.send_header("Content-Type", "video/mp4")
        self.send_header("Accept-Ranges", "bytes")
        self.send_header("Content-Length", str(length))
        if status == 206:
            self.send_header("Content-Range", f"bytes {start}-{end}/{size}")
        if qs.get("dl", ["0"])[0] == "1":
            self.send_header("Content-Disposition", f"attachment; filename=\"{os.path.basename(p)}\"")
        self.end_headers()
        if self.command == "HEAD":
            return
        with open(p, "rb") as f:
            f.seek(start); remaining = length
            while remaining > 0:
                chunk = f.read(min(65536, remaining))
                if not chunk:
                    break
                try:
                    self.wfile.write(chunk)
                except (BrokenPipeError, ConnectionResetError):
                    break
                remaining -= len(chunk)

    do_HEAD = do_GET
    def log_message(self, *a): pass

if __name__ == "__main__":
    threading.Thread(target=cleanup_loop, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", PORT), H).serve_forever()
