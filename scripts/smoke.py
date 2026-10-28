import json
from urllib.request import urlopen
with urlopen('http://127.0.0.1:8000/api/v1/modules', timeout=5) as response:
    modules = json.load(response)['data']
    assert len(modules) == 13
    assert modules[0]['status'] == 'available'
    assert modules[1]['status'] == 'available'
    assert all(module['status'] == ('available' if module['id'] in {'identity','catalog','ordering','inventory','fulfillment','sales','shifts','reporting'} else 'planned') for module in modules)
with urlopen('http://127.0.0.1:8080', timeout=5) as response:
    html = response.read().decode()
    assert 'Katalog dan harga' in html
    assert 'API belum tersedia' not in html
print('PASS: API manifest and live web-to-API integration')
