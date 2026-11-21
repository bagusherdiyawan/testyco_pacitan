import json
from urllib.request import urlopen
with urlopen('http://127.0.0.1:8000/api/v1/modules', timeout=5) as response:
    modules = json.load(response)['data']
    assert len(modules) == 14
    assert modules[0]['status'] == 'available'
    assert modules[1]['status'] == 'available'
    assert all(module['status'] == 'available' for module in modules)
with urlopen('http://127.0.0.1:8080', timeout=5) as response:
    html = response.read().decode()
    assert 'Operasional dalam satu ekosistem' in html
    assert 'Peta modul' not in html
    assert 'API belum tersedia' not in html
print('PASS: API manifest and live web-to-API integration')
