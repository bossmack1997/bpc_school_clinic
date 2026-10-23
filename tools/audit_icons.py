import re, os, urllib.request
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
used = set()
for dirpath, dirnames, filenames in os.walk(root):
    dirnames[:] = [name for name in dirnames if name not in {'.git', 'vendor', 'scratch', 'node_modules'}]
    for fn in filenames:
        if fn.lower().endswith((('.php', '.html', '.js', '.css'))):
            path = os.path.join(dirpath, fn)
            try:
                text = open(path, encoding='utf-8', errors='ignore').read()
            except Exception:
                continue
            used |= set(re.findall(r'bi bi-([a-z0-9-]+)', text))
            used |= set(re.findall(r'bi-([a-z0-9-]+)(?=\s|"|\'|>)', text))
css = urllib.request.urlopen('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css').read().decode('utf-8')
icons = set(re.findall(r'\.bi-([a-z0-9-]+)::before', css))
missing = sorted(name for name in used if name not in icons)
print('used', len(used))
print('supported', len(icons))
print('missing', len(missing))
for name in missing:
    print(name)
