import asyncio
import base64
import json
import random
import secrets
import ssl
import string
import time
import urllib.parse
import urllib.request
from collections import defaultdict
from pathlib import Path

# ─── Listener ─────────────────────────────────────────────────────────────────

LISTEN_HOST = "0.0.0.0"
LISTEN_PORT = 443

# ─── Users ────────────────────────────────────────────────────────────────────

USERS_FILE           = "/home/adminisusers/proxy_users.json"
USERS_SECURE_FILE    = "/home/adminisusers/proxy_users_secure.json"
ACCOUNT_COUNT        = 100   # existing HTTP accounts — untouched
SECURE_ACCOUNT_COUNT = 100   # new HTTPS/SOCKS accounts

# ─── Upstream sources ─────────────────────────────────────────────────────────

H_PROXY_URL     = "https://hproxy.com/api/proxy-list"
PROXYSCRAPE_URL = (
    "https://api.proxyscrape.com/v4/free-proxy-list/get"
    "?request=display_proxies&proxy_format=protocolipport&format=text"
)

MAX_PROXIES        = 500   # HTTP pool cap
MAX_PROXIES_SECURE = 500   # HTTPS/SOCKS pool cap
UPDATE_SECONDS     = 120

# ─── Timeouts & buffers ───────────────────────────────────────────────────────

CONNECT_TIMEOUT         = 5
CLIENT_HEADER_TIMEOUT   = 10
UPSTREAM_HEADER_TIMEOUT = 8
IDLE_TIMEOUT            = 60
BUFFER_SIZE             = 65536
HEADER_LIMIT            = 65536

# ─── Rotation ─────────────────────────────────────────────────────────────────

MAX_RETRIES          = 6
MAX_CONSECUTIVE_USES = 5
BAD_COOLDOWN         = 60

# ─── HProxy quality filter ────────────────────────────────────────────────────

MIN_UPTIME  = 95
MAX_LATENCY = 500

# ─── State ────────────────────────────────────────────────────────────────────

PROXIES        = []   # [(ip, port), ...]          — HTTP pool
PROXIES_SECURE = []   # [(proto, ip, port), ...]   — HTTPS/SOCKS pool

BAD_UNTIL        = {}   # (ip, port)          → monotonic time
BAD_UNTIL_SECURE = {}   # (proto, ip, port)   → monotonic time
USE_COUNT        = defaultdict(int)
USE_COUNT_SECURE = defaultdict(int)

USERS        = {}   # username → password  (HTTP accounts)
USERS_SECURE = {}   # username → password  (HTTPS/SOCKS accounts)

LOCK = asyncio.Lock()


# ─── Utility ──────────────────────────────────────────────────────────────────

def random_string(length):
    chars = string.ascii_letters + string.digits
    return "".join(secrets.choice(chars) for _ in range(length))


# ─── Users ────────────────────────────────────────────────────────────────────

def load_users():
    global USERS, USERS_SECURE

    # ── HTTP accounts (existing, preserved as-is) ──────────────────────────
    path = Path(USERS_FILE)
    if path.exists():
        try:
            USERS = json.loads(path.read_text())
        except Exception:
            USERS = {}

    while len(USERS) < ACCOUNT_COUNT:
        username = "user_" + random_string(10)
        password = random_string(18)
        if username not in USERS:
            USERS[username] = password

    path.write_text(json.dumps(USERS, indent=2))
    print(f"[AUTH] {len(USERS)} HTTP accounts loaded", flush=True)

    # ── Secure accounts (HTTPS/SOCKS — new) ───────────────────────────────
    sec_path = Path(USERS_SECURE_FILE)
    if sec_path.exists():
        try:
            USERS_SECURE = json.loads(sec_path.read_text())
        except Exception:
            USERS_SECURE = {}

    while len(USERS_SECURE) < SECURE_ACCOUNT_COUNT:
        username = "sec_" + random_string(10)
        password = random_string(18)
        if username not in USERS_SECURE:
            USERS_SECURE[username] = password

    sec_path.write_text(json.dumps(USERS_SECURE, indent=2))
    print(f"[AUTH] {len(USERS_SECURE)} secure accounts loaded", flush=True)


def auth_type(headers):
    """Returns 'http', 'secure', or None."""
    value = headers.get("proxy-authorization", "")
    if not value.lower().startswith("basic "):
        return None
    try:
        decoded  = base64.b64decode(value[6:], validate=True).decode("utf-8")
        username, password = decoded.split(":", 1)
        if USERS.get(username) == password:
            return "http"
        if USERS_SECURE.get(username) == password:
            return "secure"
    except Exception:
        pass
    return None


# ─── Proxy sources ────────────────────────────────────────────────────────────

def download_proxy_list():
    """HProxy — JSON list of HTTP proxies."""
    params = urllib.parse.urlencode({
        "format":         "json",
        "protocol":       "http",
        "min_uptime_pct": MIN_UPTIME,
        "max_latency_ms": MAX_LATENCY,
        "sort":           "uptime",
        "limit":          MAX_PROXIES,
    })
    request = urllib.request.Request(
        H_PROXY_URL + "?" + params,
        headers={
            "User-Agent": "HProxy-Gateway/3.0",
            "Accept":     "application/json",
        },
    )
    context = ssl.create_default_context()
    with urllib.request.urlopen(request, timeout=15, context=context) as resp:
        return json.loads(resp.read())


def download_proxyscrape():
    """
    ProxyScrape — text list, one entry per line: proto://ip:port
    Returns:
        http_list   : [(ip, port), ...]
        secure_list : [(proto, ip, port), ...]   proto ∈ {https, socks4, socks5}
    """
    request = urllib.request.Request(
        PROXYSCRAPE_URL,
        headers={
            "User-Agent": "HProxy-Gateway/3.0",
            "Accept":     "text/plain",
        },
    )
    context = ssl.create_default_context()
    with urllib.request.urlopen(request, timeout=15, context=context) as resp:
        text = resp.read().decode("utf-8", errors="ignore")

    http_list   = []
    secure_list = []

    for line in text.splitlines():
        line = line.strip()
        if not line or "://" not in line:
            continue
        try:
            proto, rest = line.split("://", 1)
            proto = proto.lower()
            ip, port_s = rest.rsplit(":", 1)
            port = int(port_s)
            if not ip or not (1 <= port <= 65535):
                continue
            if proto == "http":
                http_list.append((ip, port))
            elif proto in ("https", "socks4", "socks5"):
                secure_list.append((proto, ip, port))
        except Exception:
            continue

    return http_list, secure_list


async def update_proxies():
    global PROXIES, PROXIES_SECURE

    while True:
        hproxy_list = []
        ps_http     = []
        ps_secure   = []

        try:
            raw = await asyncio.to_thread(download_proxy_list)
            for item in raw:
                try:
                    ip   = item.get("ip")
                    port = int(item.get("port"))
                    if ip and 1 <= port <= 65535:
                        hproxy_list.append((ip, port))
                except Exception:
                    continue
        except Exception as e:
            print(f"[HProxy UPDATE] {type(e).__name__}: {e}", flush=True)

        try:
            ps_http, ps_secure = await asyncio.to_thread(download_proxyscrape)
        except Exception as e:
            print(f"[ProxyScrape UPDATE] {type(e).__name__}: {e}", flush=True)

        # Merge; HProxy HTTP takes precedence (already quality-filtered)
        all_http   = list(dict.fromkeys(hproxy_list + ps_http))[:MAX_PROXIES]
        all_secure = list(dict.fromkeys(ps_secure))[:MAX_PROXIES_SECURE]

        async with LOCK:
            PROXIES        = all_http
            PROXIES_SECURE = all_secure

            now = time.monotonic()

            for key in list(BAD_UNTIL):
                if BAD_UNTIL[key] <= now:
                    del BAD_UNTIL[key]

            for key in list(BAD_UNTIL_SECURE):
                if BAD_UNTIL_SECURE[key] <= now:
                    del BAD_UNTIL_SECURE[key]

            valid_http   = set(PROXIES)
            valid_secure = set(PROXIES_SECURE)

            for p in list(USE_COUNT):
                if p not in valid_http:
                    del USE_COUNT[p]

            for p in list(USE_COUNT_SECURE):
                if p not in valid_secure:
                    del USE_COUNT_SECURE[p]

        print(f"[HTTP]   {len(all_http)} upstreams loaded", flush=True)
        print(f"[SECURE] {len(all_secure)} upstreams loaded", flush=True)

        await asyncio.sleep(UPDATE_SECONDS)


# ─── Proxy selection ──────────────────────────────────────────────────────────

def _choose(pool, bad_until, use_count, excluded):
    """Round-robin chooser with bad-proxy exclusion. Must be called inside LOCK."""
    if not pool:
        return None

    now = time.monotonic()

    available = [
        p for p in pool
        if p not in excluded
        and (p not in bad_until or bad_until[p] <= now)
    ]

    if not available:
        return None

    fresh = [p for p in available if use_count[p] < MAX_CONSECUTIVE_USES]

    if fresh:
        minimum    = min(use_count[p] for p in fresh)
        candidates = [p for p in fresh if use_count[p] == minimum]
        proxy      = random.choice(candidates)
    else:
        for p in available:
            use_count[p] = 0
        proxy = random.choice(available)

    use_count[proxy] += 1
    return proxy


async def choose_proxy(excluded):
    async with LOCK:
        return _choose(PROXIES, BAD_UNTIL, USE_COUNT, excluded)


async def choose_secure_proxy(excluded):
    async with LOCK:
        return _choose(PROXIES_SECURE, BAD_UNTIL_SECURE, USE_COUNT_SECURE, excluded)


async def mark_bad(proxy):
    async with LOCK:
        BAD_UNTIL[proxy] = time.monotonic() + BAD_COOLDOWN


async def mark_bad_secure(proxy):
    async with LOCK:
        BAD_UNTIL_SECURE[proxy] = time.monotonic() + BAD_COOLDOWN


# ─── Secure tunnel builders ───────────────────────────────────────────────────

async def open_socks5_tunnel(ip, port, host, target_port):
    """
    SOCKS5 (no-auth) tunnel to host:target_port through ip:port.
    Uses domain-name ATYP (0x03) so DNS resolves at the proxy.
    """
    reader, writer = await asyncio.wait_for(
        asyncio.open_connection(ip, port, limit=HEADER_LIMIT),
        timeout=CONNECT_TIMEOUT,
    )

    # Greeting — propose no-auth
    writer.write(b"\x05\x01\x00")
    await writer.drain()

    resp = await asyncio.wait_for(reader.readexactly(2), timeout=CONNECT_TIMEOUT)
    if resp[0] != 0x05 or resp[1] != 0x00:
        writer.close()
        raise ConnectionError(f"SOCKS5 auth rejected: {resp.hex()}")

    # CONNECT command
    host_b = host.encode("utf-8")
    writer.write(
        b"\x05\x01\x00\x03"
        + bytes([len(host_b)])
        + host_b
        + target_port.to_bytes(2, "big")
    )
    await writer.drain()

    # Response (4-byte header)
    hdr = await asyncio.wait_for(reader.readexactly(4), timeout=CONNECT_TIMEOUT)
    if hdr[1] != 0x00:
        writer.close()
        raise ConnectionError(f"SOCKS5 CONNECT failed: rep={hdr[1]:#04x}")

    # Drain bound-address field
    atyp = hdr[3]
    if atyp == 0x01:
        await asyncio.wait_for(reader.readexactly(6), timeout=CONNECT_TIMEOUT)
    elif atyp == 0x03:
        n = (await asyncio.wait_for(reader.readexactly(1), timeout=CONNECT_TIMEOUT))[0]
        await asyncio.wait_for(reader.readexactly(n + 2), timeout=CONNECT_TIMEOUT)
    elif atyp == 0x04:
        await asyncio.wait_for(reader.readexactly(18), timeout=CONNECT_TIMEOUT)

    return reader, writer


async def open_socks4_tunnel(ip, port, host, target_port):
    """
    SOCKS4a tunnel to host:target_port through ip:port.
    Fake DSTIP 0.0.0.1 triggers 4a domain-name extension.
    """
    reader, writer = await asyncio.wait_for(
        asyncio.open_connection(ip, port, limit=HEADER_LIMIT),
        timeout=CONNECT_TIMEOUT,
    )

    host_b = host.encode("utf-8") + b"\x00"
    writer.write(
        b"\x04\x01"
        + target_port.to_bytes(2, "big")
        + b"\x00\x00\x00\x01"   # fake IP → triggers 4a extension
        + b"\x00"                # empty user-ID
        + host_b
    )
    await writer.drain()

    resp = await asyncio.wait_for(reader.readexactly(8), timeout=CONNECT_TIMEOUT)
    if resp[1] != 0x5A:
        writer.close()
        raise ConnectionError(f"SOCKS4 CONNECT failed: {resp.hex()}")

    return reader, writer


async def open_https_proxy_connect(ip, port, host, target_port):
    """
    TLS connect to an HTTPS upstream proxy, then CONNECT to host:target_port.
    Returns (reader, writer) with tunnel already established.
    """
    ssl_ctx = ssl.create_default_context()
    ssl_ctx.check_hostname = False
    ssl_ctx.verify_mode    = ssl.CERT_NONE

    reader, writer = await asyncio.wait_for(
        asyncio.open_connection(ip, port, ssl=ssl_ctx, limit=HEADER_LIMIT),
        timeout=CONNECT_TIMEOUT,
    )

    req = (
        f"CONNECT {host}:{target_port} HTTP/1.1\r\n"
        f"Host: {host}:{target_port}\r\n"
        f"Proxy-Connection: Keep-Alive\r\n"
        f"Connection: Keep-Alive\r\n"
        f"\r\n"
    ).encode("latin1")
    writer.write(req)
    await writer.drain()

    resp       = await asyncio.wait_for(
        reader.readuntil(b"\r\n\r\n"),
        timeout=UPSTREAM_HEADER_TIMEOUT,
    )
    first_line = resp.split(b"\r\n", 1)[0]

    if b" 200 " not in first_line:
        writer.close()
        raise ConnectionError(
            f"HTTPS proxy CONNECT failed: {first_line.decode('latin1','replace')}"
        )

    return reader, writer


async def _open_secure_tunnel(proxy, host, target_port):
    """Dispatch to the correct tunnel opener based on proxy protocol."""
    proto, ip, port = proxy

    if proto == "socks5":
        return await open_socks5_tunnel(ip, port, host, target_port)
    if proto == "socks4":
        return await open_socks4_tunnel(ip, port, host, target_port)
    if proto == "https":
        return await open_https_proxy_connect(ip, port, host, target_port)

    raise ValueError(f"Unknown secure protocol: {proto}")


# ─── Shared low-level helpers ─────────────────────────────────────────────────

async def close_writer(writer):
    if writer is None:
        return
    try:
        writer.close()
        await writer.wait_closed()
    except Exception:
        pass


async def connect_upstream(proxy):
    return await asyncio.wait_for(
        asyncio.open_connection(proxy[0], proxy[1], limit=HEADER_LIMIT),
        timeout=CONNECT_TIMEOUT,
    )


async def read_headers(reader):
    return await asyncio.wait_for(
        reader.readuntil(b"\r\n\r\n"),
        timeout=UPSTREAM_HEADER_TIMEOUT,
    )


async def relay(reader, writer):
    try:
        while True:
            data = await asyncio.wait_for(
                reader.read(BUFFER_SIZE),
                timeout=IDLE_TIMEOUT,
            )
            if not data:
                break
            writer.write(data)
            await writer.drain()
    except Exception:
        pass


async def tunnel(client_reader, client_writer, upstream_reader, upstream_writer):
    a = asyncio.create_task(relay(client_reader, upstream_writer))
    b = asyncio.create_task(relay(upstream_reader, client_writer))
    try:
        await asyncio.wait([a, b], return_when=asyncio.FIRST_COMPLETED)
    finally:
        a.cancel()
        b.cancel()
        await asyncio.gather(a, b, return_exceptions=True)
        await close_writer(upstream_writer)
        await close_writer(client_writer)


async def send_502(writer):
    try:
        writer.write(
            b"HTTP/1.1 502 Bad Gateway\r\n"
            b"Content-Length: 0\r\n"
            b"Connection: close\r\n"
            b"\r\n"
        )
        await writer.drain()
    except Exception:
        pass
    await close_writer(writer)


# ─── HTTP upstream handlers (original pool) ───────────────────────────────────

async def handle_connect(reader, writer, target):
    if ":" not in target:
        await send_502(writer)
        return

    host, port_text = target.rsplit(":", 1)
    try:
        port = int(port_text)
        if not 1 <= port <= 65535:
            raise ValueError
    except Exception:
        await send_502(writer)
        return

    excluded = set()

    for attempt in range(1, MAX_RETRIES + 1):
        proxy = await choose_proxy(excluded)
        if not proxy:
            print("[CONNECT] No upstream available", flush=True)
            break

        excluded.add(proxy)
        upstream_reader = None
        upstream_writer = None

        try:
            print(
                f"[CONNECT] {host}:{port} -> "
                f"{proxy[0]}:{proxy[1]} (try {attempt})",
                flush=True,
            )
            upstream_reader, upstream_writer = await connect_upstream(proxy)

            request = (
                f"CONNECT {host}:{port} HTTP/1.1\r\n"
                f"Host: {host}:{port}\r\n"
                f"Proxy-Connection: Keep-Alive\r\n"
                f"Connection: Keep-Alive\r\n"
                f"\r\n"
            ).encode("latin1")

            upstream_writer.write(request)
            await upstream_writer.drain()

            response   = await read_headers(upstream_reader)
            first_line = response.split(b"\r\n", 1)[0]

            print(
                f"[UPSTREAM] {proxy[0]}:{proxy[1]} -> "
                f"{first_line.decode('latin1','replace')}",
                flush=True,
            )

            if not first_line.startswith(b"HTTP/") or b" 200 " not in first_line:
                await mark_bad(proxy)
                await close_writer(upstream_writer)
                continue

            writer.write(
                b"HTTP/1.1 200 Connection Established\r\n"
                b"Connection: Keep-Alive\r\n"
                b"Proxy-Agent: HProxy-Gateway\r\n"
                b"\r\n"
            )
            await writer.drain()

            print(f"[TUNNEL] {host}:{port} established", flush=True)
            await tunnel(reader, writer, upstream_reader, upstream_writer)
            return

        except Exception as e:
            print(
                f"[UPSTREAM ERROR] {proxy[0]}:{proxy[1]} -> "
                f"{type(e).__name__}: {e}",
                flush=True,
            )
            await mark_bad(proxy)
            await close_writer(upstream_writer)

    await send_502(writer)


async def handle_http(reader, writer, raw):
    lines = raw.split(b"\r\n")
    if not lines:
        await send_502(writer)
        return

    try:
        first  = lines[0].decode("latin1").split()
        if len(first) < 2:
            raise ValueError
        method = first[0]
        target = first[1]
    except Exception:
        await send_502(writer)
        return

    headers = {}
    for line in lines[1:]:
        if not line:
            break
        if b":" not in line:
            continue
        key, value = line.split(b":", 1)
        headers[key.decode("latin1").lower()] = value.decode("latin1").strip()

    if not target.startswith(("http://", "https://")):
        host = headers.get("host")
        if not host:
            await send_502(writer)
            return
        target = "http://" + host + target

    output = [f"{method} {target} HTTP/1.1"]
    for line in lines[1:]:
        if not line:
            break
        lower = line.lower()
        if (
            lower.startswith(b"proxy-authorization:")
            or lower.startswith(b"proxy-connection:")
        ):
            continue
        output.append(line.decode("latin1"))

    request = ("\r\n".join(output) + "\r\n\r\n").encode("latin1")

    content_length = headers.get("content-length")
    try:
        body_length = int(content_length) if content_length else 0
    except Exception:
        body_length = 0

    excluded = set()

    for attempt in range(1, MAX_RETRIES + 1):
        proxy = await choose_proxy(excluded)
        if not proxy:
            break

        excluded.add(proxy)
        upstream_reader = None
        upstream_writer = None

        try:
            upstream_reader, upstream_writer = await connect_upstream(proxy)
            upstream_writer.write(request)
            await upstream_writer.drain()

            remaining = body_length
            while remaining > 0:
                chunk = await reader.read(min(BUFFER_SIZE, remaining))
                if not chunk:
                    raise ConnectionError("client body closed")
                upstream_writer.write(chunk)
                await upstream_writer.drain()
                remaining -= len(chunk)

            response = await read_headers(upstream_reader)
            writer.write(response)
            await writer.drain()

            await tunnel(reader, writer, upstream_reader, upstream_writer)
            return

        except Exception as e:
            print(
                f"[HTTP ERROR] {proxy[0]}:{proxy[1]} -> {type(e).__name__}: {e}",
                flush=True,
            )
            await mark_bad(proxy)
            await close_writer(upstream_writer)

    await send_502(writer)


# ─── HTTPS/SOCKS upstream handlers (secure pool) ─────────────────────────────

async def handle_connect_secure(reader, writer, target):
    """
    CONNECT tunnel routed through the HTTPS/SOCKS pool.
    SOCKS proxies open a tunnel directly; HTTPS proxies use TLS + CONNECT.
    Client-facing protocol is identical to the HTTP variant.
    """
    if ":" not in target:
        await send_502(writer)
        return

    host, port_text = target.rsplit(":", 1)
    try:
        port = int(port_text)
        if not 1 <= port <= 65535:
            raise ValueError
    except Exception:
        await send_502(writer)
        return

    excluded = set()

    for attempt in range(1, MAX_RETRIES + 1):
        proxy = await choose_secure_proxy(excluded)
        if not proxy:
            print("[CONNECT-SEC] No upstream available", flush=True)
            break

        excluded.add(proxy)
        proto, ip, pport = proxy
        upstream_reader  = None
        upstream_writer  = None

        try:
            print(
                f"[CONNECT-SEC] {host}:{port} -> "
                f"{proto}://{ip}:{pport} (try {attempt})",
                flush=True,
            )

            upstream_reader, upstream_writer = await _open_secure_tunnel(
                proxy, host, port
            )

            writer.write(
                b"HTTP/1.1 200 Connection Established\r\n"
                b"Connection: Keep-Alive\r\n"
                b"Proxy-Agent: HProxy-Gateway\r\n"
                b"\r\n"
            )
            await writer.drain()

            print(
                f"[TUNNEL-SEC] {host}:{port} via {proto}://{ip}:{pport}",
                flush=True,
            )
            await tunnel(reader, writer, upstream_reader, upstream_writer)
            return

        except Exception as e:
            print(
                f"[SEC ERROR] {proto}://{ip}:{pport} -> {type(e).__name__}: {e}",
                flush=True,
            )
            await mark_bad_secure(proxy)
            await close_writer(upstream_writer)

    await send_502(writer)


async def handle_http_secure(reader, writer, raw):
    """
    Plain HTTP request routed through the HTTPS/SOCKS pool.

    SOCKS upstream  — SOCKS tunnel to destination, then relative-path HTTP.
    HTTPS upstream  — TLS connection to proxy, then absolute-URL HTTP.

    The client sees standard HTTP proxy behaviour either way.
    """
    lines = raw.split(b"\r\n")
    if not lines:
        await send_502(writer)
        return

    try:
        first  = lines[0].decode("latin1").split()
        if len(first) < 2:
            raise ValueError
        method = first[0]
        target = first[1]
    except Exception:
        await send_502(writer)
        return

    headers = {}
    for line in lines[1:]:
        if not line:
            break
        if b":" not in line:
            continue
        key, value = line.split(b":", 1)
        headers[key.decode("latin1").lower()] = value.decode("latin1").strip()

    # ── Parse destination ──────────────────────────────────────────────────
    if target.startswith(("http://", "https://")):
        parsed    = urllib.parse.urlparse(target)
        dest_host = parsed.hostname or ""
        dest_port = parsed.port or (443 if parsed.scheme == "https" else 80)
        rel_path  = parsed.path or "/"
        if parsed.query:
            rel_path += "?" + parsed.query
    else:
        host_hdr  = headers.get("host", "")
        if ":" in host_hdr:
            dest_host, dp = host_hdr.rsplit(":", 1)
            dest_port = int(dp)
        else:
            dest_host = host_hdr
            dest_port = 80
        rel_path = target

    if not dest_host:
        await send_502(writer)
        return

    abs_target = (
        target
        if target.startswith(("http://", "https://"))
        else f"http://{dest_host}:{dest_port}{target}"
    )

    # ── Strip proxy headers ────────────────────────────────────────────────
    clean_headers = []
    for line in lines[1:]:
        if not line:
            break
        lower = line.lower()
        if (
            lower.startswith(b"proxy-authorization:")
            or lower.startswith(b"proxy-connection:")
        ):
            continue
        clean_headers.append(line.decode("latin1"))

    content_length = headers.get("content-length")
    try:
        body_length = int(content_length) if content_length else 0
    except Exception:
        body_length = 0

    excluded = set()

    for attempt in range(1, MAX_RETRIES + 1):
        proxy = await choose_secure_proxy(excluded)
        if not proxy:
            break

        excluded.add(proxy)
        proto, ip, pport = proxy
        upstream_reader  = None
        upstream_writer  = None

        try:
            if proto in ("socks4", "socks5"):
                # Tunnel to destination; send relative-path request
                if proto == "socks5":
                    upstream_reader, upstream_writer = await open_socks5_tunnel(
                        ip, pport, dest_host, dest_port
                    )
                else:
                    upstream_reader, upstream_writer = await open_socks4_tunnel(
                        ip, pport, dest_host, dest_port
                    )
                request = (
                    f"{method} {rel_path} HTTP/1.1\r\n"
                    + "\r\n".join(clean_headers)
                    + "\r\n\r\n"
                ).encode("latin1")

            else:  # https upstream proxy
                # Connect over TLS; send absolute-URL request
                ssl_ctx = ssl.create_default_context()
                ssl_ctx.check_hostname = False
                ssl_ctx.verify_mode    = ssl.CERT_NONE
                upstream_reader, upstream_writer = await asyncio.wait_for(
                    asyncio.open_connection(
                        ip, pport, ssl=ssl_ctx, limit=HEADER_LIMIT
                    ),
                    timeout=CONNECT_TIMEOUT,
                )
                request = (
                    f"{method} {abs_target} HTTP/1.1\r\n"
                    + "\r\n".join(clean_headers)
                    + "\r\n\r\n"
                ).encode("latin1")

            upstream_writer.write(request)
            await upstream_writer.drain()

            # Forward request body
            remaining = body_length
            while remaining > 0:
                chunk = await reader.read(min(BUFFER_SIZE, remaining))
                if not chunk:
                    raise ConnectionError("client body closed")
                upstream_writer.write(chunk)
                await upstream_writer.drain()
                remaining -= len(chunk)

            response = await read_headers(upstream_reader)
            writer.write(response)
            await writer.drain()

            await tunnel(reader, writer, upstream_reader, upstream_writer)
            return

        except Exception as e:
            print(
                f"[HTTP-SEC ERROR] {proto}://{ip}:{pport} -> "
                f"{type(e).__name__}: {e}",
                flush=True,
            )
            await mark_bad_secure(proxy)
            await close_writer(upstream_writer)

    await send_502(writer)


# ─── Client dispatcher ────────────────────────────────────────────────────────

async def client(reader, writer):
    try:
        raw = await asyncio.wait_for(
            reader.readuntil(b"\r\n\r\n"),
            timeout=CLIENT_HEADER_TIMEOUT,
        )

        if len(raw) > HEADER_LIMIT:
            await close_writer(writer)
            return

        lines = raw.split(b"\r\n")
        first = lines[0].decode("latin1").split()

        if len(first) < 2:
            await close_writer(writer)
            return

        headers = {}
        for line in lines[1:]:
            if not line:
                break
            if b":" not in line:
                continue
            key, value = line.split(b":", 1)
            headers[key.decode("latin1").lower()] = value.decode("latin1").strip()

        account = auth_type(headers)

        if account is None:
            writer.write(
                b"HTTP/1.1 407 Proxy Authentication Required\r\n"
                b"Proxy-Authenticate: Basic realm=\"HProxy-Gateway\"\r\n"
                b"Content-Length: 0\r\n"
                b"Connection: close\r\n"
                b"\r\n"
            )
            await writer.drain()
            await close_writer(writer)
            return

        method = first[0].upper()
        print(f"[CLIENT] [{account}] {method} {first[1]}", flush=True)

        if method == "CONNECT":
            if account == "secure":
                await handle_connect_secure(reader, writer, first[1])
            else:
                await handle_connect(reader, writer, first[1])
        else:
            if account == "secure":
                await handle_http_secure(reader, writer, raw)
            else:
                await handle_http(reader, writer, raw)

    except Exception as e:
        print(f"[CLIENT ERROR] {type(e).__name__}: {e}", flush=True)
        await close_writer(writer)


# ─── Entry point ──────────────────────────────────────────────────────────────

async def main():
    load_users()
    asyncio.create_task(update_proxies())

    server = await asyncio.start_server(
        client,
        LISTEN_HOST,
        LISTEN_PORT,
        limit=HEADER_LIMIT,
        backlog=16384,
        reuse_address=True,
    )

    print("[OK] HProxy Gateway started",  flush=True)
    print("[OK] Listening: 0.0.0.0:443",  flush=True)
    print(
        f"[OK] HProxy quality filter: "
        f"uptime>={MIN_UPTIME}%, latency<={MAX_LATENCY}ms",
        flush=True,
    )
    print(f"[OK] Max upstream uses: {MAX_CONSECUTIVE_USES}", flush=True)
    print("[OK] Dual-pool mode: HTTP + HTTPS/SOCKS", flush=True)

    async with server:
        await server.serve_forever()


if __name__ == "__main__":
    try:
        import uvloop
        uvloop.install()
        print("[OK] uvloop enabled", flush=True)
    except Exception:
        print("[INFO] Standard asyncio", flush=True)

    asyncio.run(main())
