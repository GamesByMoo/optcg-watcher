"""Serves the three fixture shops used by shops.local.json (Ctrl+C to stop)."""
import functools, http.server, os, threading
here = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures")
servers = []
for port, sub in ((8765, "shopify"), (8766, "html"), (8767, "jsonld")):
    h = functools.partial(http.server.SimpleHTTPRequestHandler, directory=os.path.join(here, sub))
    h.log_message = lambda *a: None
    s = http.server.ThreadingHTTPServer(("127.0.0.1", port), h)
    threading.Thread(target=s.serve_forever, daemon=True).start()
    servers.append(s)
print("Fixture shops on ports 8765-8767"); threading.Event().wait()
