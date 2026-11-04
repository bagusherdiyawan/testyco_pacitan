"""Isolated HTTP integration smoke: temporary DB, disposable API/web containers.
Does not seed or modify the normal development database. Requires Docker.
"""
import http.cookiejar
import csv
import io
import json
import os
import re
import secrets
import subprocess
import tempfile
import time
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, build_opener, HTTPCookieProcessor, urlopen

ROOT = Path(__file__).resolve().parents[1]
IMAGE = 'composer@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc'
NAME = 'testyco-smoke-' + uuid.uuid4().hex[:10]
PASSWORD = secrets.token_urlsafe(24) + 'Aa7'
created = []

def docker(*args):
    return subprocess.check_output(['docker', *args], text=True, stderr=subprocess.STDOUT, env={**os.environ, 'TEST_PASSWORD': PASSWORD}).strip()

def port(name):
    return int(docker('port', name, '8000/tcp').rsplit(':', 1)[1])

def ready(base):
    deadline = time.monotonic() + 45
    while time.monotonic() < deadline:
        try:
            with urlopen(base + '/up', timeout=2) as response:
                if response.status == 200:
                    return
        except (OSError, TimeoutError):
            time.sleep(.3)
    raise RuntimeError('Service readiness timed out')

def api(base, method, path, body=None, token=None, expected=200):
    headers = {'Accept': 'application/json', 'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    request = Request(base + '/api/v1/' + path, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
    try:
        response = urlopen(request, timeout=10)
    except HTTPError as error:
        response = error
    with response:
        payload = response.read()
        if response.status != expected:
            error=json.loads(payload)
            raise AssertionError(f'{method} {path}: expected {expected}, got {response.status}; errors={error.get("errors", {})}')
        return json.loads(payload) if payload else None

try:
    docker('network', 'create', NAME)
    with tempfile.TemporaryDirectory(prefix='testyco-http-') as directory:
        seed = Path(directory) / 'seed.php'
        seed.write_text('''<?php
require '/app/apps/api/vendor/autoload.php';
$app = require '/app/apps/api/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
if (!app()->environment('testing')) throw new RuntimeException('Tests only');
$org = App\\Modules\\Identity\\Infrastructure\\Organization::create(['name'=>'Smoke Pusat','type'=>'center']);
$u = App\\Models\\User::create(['name'=>'Smoke Owner','email'=>'owner@smoke.test','password'=>getenv('TEST_PASSWORD')]);
if (!Illuminate\\Support\\Facades\\Hash::check(getenv('TEST_PASSWORD'),$u->fresh()->password)) throw new RuntimeException('Test password hashing mismatch');
Illuminate\\Support\\Facades\\DB::table('organization_user')->insert(['organization_id'=>$org->id,'user_id'=>$u->id,'role'=>'owner']);
''')
        common = ['--user', f'{os.getuid()}:{os.getgid()}', '--network', NAME, '-p', '127.0.0.1::8000', '-v', f'{ROOT}:/app', '-e', 'APP_ENV=testing', '-e', 'PRIVATE_STORAGE_PATH=/tmp/private-assets', '-e', 'APP_DEBUG=false', '-e', 'NO_PROXY=api,localhost,127.0.0.1', '-e', 'no_proxy=api,localhost,127.0.0.1']
        api_name = NAME + '-api'
        docker('run', '-d', '--name', api_name, '--network-alias', 'api', *common, '-e', 'DB_DATABASE=/tmp/smoke.sqlite', '-e', 'TEST_PASSWORD', '-v', directory + ':/fixtures:ro', '-w', '/app/apps/api', IMAGE, 'sh', '-c', 'touch /tmp/smoke.sqlite && php artisan migrate --force --no-interaction && php /fixtures/seed.php && php artisan serve --host=0.0.0.0 --port=8000 --no-reload')
        created.append(api_name)
        base = 'http://127.0.0.1:' + str(port(api_name))
        ready(base)
        owner = api(base, 'POST', 'auth/login', {'email':'owner@smoke.test','password':PASSWORD,'device_name':'smoke'})['token']
        org = api(base, 'POST', 'organizations', {'name':'Smoke Mitra A'}, owner, 201)['data']['id']
        other = api(base, 'POST', 'organizations', {'name':'Smoke Mitra B'}, owner, 201)['data']['id']
        store = api(base, 'POST', f'organizations/{org}/stores', {'name':'Smoke Toko A1'}, owner, 201)['data']['id']
        lead = {'name':'Smoke Lead','email':'lead@smoke.test','password':PASSWORD,'role':'partner_lead','store_ids':[]}
        api(base, 'POST', f'organizations/{org}/users', lead, owner, 201)
        lead_token = api(base, 'POST', 'auth/login', {'email':lead['email'],'password':PASSWORD,'device_name':'smoke'})['token']
        product = {'sku':'AYAM-8','name':'Smoke Dimsum Ayam','condition':'frozen','pack_size':8,'portion_size':4,'retail_price':3000,'wholesale_price':18000}
        local_product_id = api(base, 'POST', f'organizations/{org}/products', product, lead_token, 201)['data']['id']
        api(base, 'GET', f'stores/{store}/catalog', token=owner, expected=403)
        api(base, 'GET', f'organizations/{other}/users', token=lead_token, expected=403)
        catalog = api(base, 'GET', f'stores/{store}/catalog', token=lead_token)
        assert catalog['data'][0]['retail_price'] == 3000
        assert 'wholesale_price' not in catalog['data'][0]
        web_name = NAME + '-web'
        docker('run', '-d', '--name', web_name, *common, '-e', 'APP_ENV=local', '-e', 'API_BASE_URL=http://api:8000', '-w', '/app/apps/web', IMAGE, 'php', 'artisan', 'serve', '--host=0.0.0.0', '--port=8000', '--no-reload')
        created.append(web_name)
        web = 'http://127.0.0.1:' + str(port(web_name))
        ready(web)
        opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        try:
            opener.open(Request(web + '/login', data=b'email=missing%40smoke.test&password=invalid'))
            raise AssertionError('Web accepted a POST without CSRF token')
        except HTTPError as error:
            assert error.code == 419, f'Missing CSRF expected 419, got {error.code}'
        html = opener.open(web + '/login').read().decode()
        csrf = re.search(r'name="_token" value="([^"]+)"', html).group(1)
        from urllib.parse import urlencode
        login_response = opener.open(Request(web + '/login', data=urlencode({'_token':csrf,'email':lead['email'],'password':PASSWORD}).encode()))
        html = login_response.read().decode()
        assert 'Smoke Mitra A' in html and 'Smoke Mitra B' not in html, f'Web login ended at {login_response.url}; heading={re.findall(r"<h1>([^<]*)</h1>",html)}; error={re.findall(r"<li>([^<]*)</li>",html)}'
        html = opener.open(web + f'/app/organizations/{org}/catalog').read().decode()
        assert 'Smoke Dimsum Ayam' in html and '18000' in html
        html = opener.open(web + f'/app/organizations/{org}').read().decode()
        assert 'Smoke Toko A1' in html and 'Smoke Lead' in html
        # Supply workflow is tested through real web forms and API, using only this temporary DB.
        from html.parser import HTMLParser
        class Forms(HTMLParser):
            def __init__(self):
                super().__init__(); self.forms = {}; self.current = None
            def handle_starttag(self, tag, attrs):
                attrs = dict(attrs)
                if tag == 'form':
                    self.current = attrs.get('action'); self.forms[self.current] = {}
                if tag == 'input' and self.current and attrs.get('type') == 'hidden':
                    self.forms[self.current][attrs['name']] = attrs.get('value', '')
            def handle_endtag(self, tag):
                if tag == 'form': self.current = None
        def fields(html, action):
            parser = Forms(); parser.feed(html); return parser.forms[action]
        def web_post(path, values):
            return opener.open(Request(web + path, data=urlencode(values).encode()))
        center = next(o['id'] for o in api(base, 'GET', 'organizations', token=owner)['data'] if o['type'] == 'center')
        source = api(base, 'POST', f'organizations/{center}/stores', {'name':'Smoke Gudang','address':'Pacitan'}, owner, 201)['data']['id']
        supplier_id = api(base, 'POST', f'organizations/{center}/products', product, owner, 201)['data']['id']
        intake_body={'product_id':supplier_id,'batch_code':'SMOKE-BATCH','expires_on':'2030-01-01','quantity_packs':20,'note':'Test physical warehouse receipt'}
        intake_catalogue=api(base,'GET',f'stores/{source}/inventory/intake-products',token=owner)
        assert intake_catalogue['can_intake'] and intake_catalogue['store']['id']==source and intake_catalogue['data'][0]['id']==supplier_id
        assert 'wholesale_price' not in intake_catalogue['data'][0] and 'retail_price' not in intake_catalogue['data'][0]
        intake_quote=api(base,'POST',f'stores/{source}/inventory/intakes/preview',intake_body,owner)['data']
        assert intake_quote['quantity_pieces']==160 and intake_quote['pack_size']==8
        assert api(base,'GET',f'stores/{source}/inventory/batches',token=owner)['data']==[]
        intake_request={**intake_body,'operation_id':str(uuid.uuid4()),'review_hash':intake_quote['review_hash']}
        api(base,'POST',f'stores/{source}/inventory/intakes',{**intake_request,'operation_id':str(uuid.uuid4()),'quantity_packs':21},owner,expected=409)
        batch=api(base,'POST',f'stores/{source}/inventory/intakes',intake_request,owner,201)['data']
        assert api(base,'POST',f'stores/{source}/inventory/intakes',intake_request,owner,201)['data']['id']==batch['id']
        api(base,'GET',f'stores/{store}/inventory/intake-products',token=lead_token,expected=403)
        html = opener.open(web + f'/app/stores/{store}/orders/new').read().decode()
        create_path = f'/app/stores/{store}/orders'
        data = fields(html, create_path)
        data.update({'delivery_mode':'pickup','lines[0][supplier_product_id]':supplier_id,'lines[0][local_product_id]':local_product_id,'lines[0][quantity_packs]':'20'})
        # Native entry preview is read-only, then create is hash-bound and idempotent.
        native_body = {'delivery_mode':'pickup','note':'Native reviewed request','lines':[{'supplier_product_id':supplier_id,'local_product_id':local_product_id,'quantity_packs':1}]}
        native_preview = api(base,'POST',f'stores/{store}/orders/preview',native_body,lead_token)['data']
        assert native_preview['total_amount']==18000 and len(native_preview['review_hash'])==64
        native_request = {**native_body,'operation_id':str(uuid.uuid4()),'review_hash':native_preview['review_hash']}
        native_draft = api(base,'POST',f'stores/{store}/orders',native_request,lead_token,201)['data']
        assert native_draft['status']=='awaiting_approval'
        assert api(base,'POST',f'stores/{store}/orders',native_request,lead_token,201)['data']['id']==native_draft['id']
        assert api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']==[]
        assert api(base,'GET',f'stores/{source}/inventory/batches',token=owner)['data'][0]['usable_pieces']==160
        api(base,'GET',f'orders/{native_draft["id"]}',token=owner,expected=403)
        altered={**native_request,'operation_id':str(uuid.uuid4()),'lines':[{**native_body['lines'][0],'quantity_packs':2}]}
        api(base,'POST',f'stores/{store}/orders',altered,lead_token,expected=409)
        assert api(base,'GET',f'stores/{store}/operations-context',token=lead_token)['data']['order_kind']=='wholesale'
        amend_body={**native_body,'version':native_draft['version'],'lines':[{**native_body['lines'][0],'quantity_packs':2}],'note':'Reviewed amendment'}
        amend_preview=api(base,'POST',f'orders/{native_draft["id"]}/preview',amend_body,lead_token)['data']
        assert amend_preview['order_id']==native_draft['id'] and amend_preview['total_amount']==36000
        amend_request={**amend_body,'operation_id':str(uuid.uuid4()),'review_hash':amend_preview['review_hash']}
        amended=api(base,'PUT',f'orders/{native_draft["id"]}',amend_request,lead_token)['data']
        assert amended['id']==native_draft['id'] and amended['version']==2 and amended['status']=='awaiting_approval'
        assert api(base,'PUT',f'orders/{native_draft["id"]}',amend_request,lead_token)['data']['version']==2
        assert api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']==[]
        response = web_post(create_path, data); html = response.read().decode(); order_id = response.url.rsplit('/', 1)[1]
        assert 'awaiting_approval' in html
        native_order = api(base, 'GET', f'orders/{order_id}', token=lead_token)['data']
        assert native_order['recipient_actions'] == {'amend':True,'approve':True,'reject':True,'cancel':True,'receive':False} and native_order['fulfillment_enabled']
        assert api(base, 'GET', 'supply/orders', token=owner)['data'] == []
        api(base, 'GET', f'orders/{order_id}', token=owner, expected=403)
        approve_path = f'/app/orders/{order_id}/approve'
        response = web_post(approve_path, fields(html, approve_path)); html = response.read().decode()
        order = api(base, 'GET', f'orders/{order_id}', token=owner)['data']
        assert order['status'] == 'approved' and order['total_amount'] == 360000
        assert order['recipient_actions'] == {'amend':False,'approve':False,'reject':False,'cancel':False,'receive':False}
        warehouse_path=f'stores/{source}/fulfillment/orders'
        assert api(base,'GET',warehouse_path,token=owner)['data'][0]['id']==order_id
        warehouse=api(base,'GET',warehouse_path+'/'+order_id,token=owner)['data']
        assert warehouse['can_process'] and not warehouse['can_dispatch'] and warehouse['source']['id']==source
        assert warehouse['order']['lines'][0]['sku']=='AYAM-8'
        api(base,'GET',warehouse_path,token=lead_token,expected=403)
        process={'operation_id':str(uuid.uuid4()),'version':warehouse['order']['version'],'source_store_id':source}
        order=api(base,'POST',f'orders/{order_id}/process',process,owner)['data']
        api(base,'POST',f'orders/{order_id}/process',process,owner)
        warehouse=api(base,'GET',warehouse_path+'/'+order_id,token=owner)['data']
        assert not warehouse['can_process'] and warehouse['can_dispatch']
        assert warehouse['order']['lines'][0]['remaining_packs']==20 and warehouse['batches'][0]['id']==batch['id']
        # Postapproval proposal holds dispatch; web recipient rejects without changing approved quote.
        change_body={'operation_id':str(uuid.uuid4()),'version':order['version'],'reason':'Physical shortage reviewed','lines':[{'order_line_id':order['lines'][0]['id'],'quantity_packs':18}]}
        change_result=api(base,'POST',f'orders/{order_id}/changes',change_body,owner,201)['data']
        assert change_result['order']['total_amount']==360000 and change_result['change']['after']['total_amount']==324000
        held=api(base,'GET',warehouse_path+'/'+order_id,token=owner)['data']
        assert held['change_pending'] and not held['can_dispatch'] and held['batches']==[]
        blocked={'operation_id':str(uuid.uuid4()),'version':change_result['order']['version'],'handler':'Held dispatch','lines':[{'order_line_id':order['lines'][0]['id'],'allocations':[{'batch_id':batch['id'],'quantity_packs':20}]}]}
        api(base,'POST',f'orders/{order_id}/shipments',blocked,owner,expected=409)
        change_html=opener.open(web+f'/app/orders/{order_id}/changes').read().decode()
        reject_path=f'/app/orders/{order_id}/changes/{change_result["change"]["id"]}/reject'
        rejection=fields(change_html,reject_path); rejection['reason']='Keep original confirmed quantity'
        web_post(reject_path,rejection).read()
        order=api(base,'GET',f'orders/{order_id}',token=owner)['data']
        assert not order['change_pending'] and order['total_amount']==360000 and order['lines'][0]['quantity_packs']==20
        dispatch = {'operation_id':str(uuid.uuid4()),'version':order['version'],'handler':'Smoke warehouse','lines':[{'order_line_id':order['lines'][0]['id'],'allocations':[{'batch_id':batch['id'],'quantity_packs':20}]}]}
        shipment = api(base, 'POST', f'orders/{order_id}/shipments', dispatch, owner, 201)['data']['shipment']
        api(base, 'POST', f'orders/{order_id}/shipments', dispatch, owner, 201)
        native_order = api(base, 'GET', f'orders/{order_id}', token=lead_token)['data']
        assert native_order['recipient_actions']['receive'] and not native_order['recipient_actions']['cancel']
        html = opener.open(web + f'/app/orders/{order_id}').read().decode()
        receive_path = f'/app/orders/{order_id}/shipments/{shipment["id"]}/receive'
        data = fields(html, receive_path)
        data.update({'lines[0][good_packs]':'18','lines[0][damaged_packs]':'2','lines[0][condition_note]':'Two damaged packs from physical check'})
        html = web_post(receive_path, data).read().decode()
        assert 'partially_received' in html
        web_post(receive_path, data).read()  # Same operation replay must not double stock.
        batch = api(base, 'GET', f'stores/{store}/inventory/batches', token=lead_token)['data'][0]
        assert batch['usable_pieces'] == 144 and batch['quarantined_pieces'] == 16
        ledger_query=urlencode({'batch_id':batch['id'],'source_type':'shipment_receipt','bucket':'quarantined','q':'SMOKE-BATCH'})
        history=api(base,'GET',f'stores/{store}/inventory/ledger?'+ledger_query,token=lead_token)
        assert history['store']['id']==store and history['total']==1 and history['data'][0]['quantity_pieces']==16
        assert history['data'][0]['pack_size']==8 and history['data'][0]['batch_code']=='SMOKE-BATCH'
        assert history['data'][0]['occurred_at'].endswith('+00:00') and 'retail_price' not in history['data'][0]
        ledger_day=datetime.fromisoformat(history['data'][0]['occurred_at']).astimezone(timezone(timedelta(hours=7))).date().isoformat()
        web_ledger_query=urlencode({'from_day':ledger_day,'to_day':ledger_day,'batch_id':batch['id'],'source_type':'shipment_receipt','bucket':'quarantined','q':'SMOKE-BATCH'})
        with opener.open(web+f'/app/stores/{store}/inventory/ledger?'+web_ledger_query) as response:
            ledger_html=response.read().decode()
            assert 'no-store' in response.headers['Cache-Control']
        assert '+16 biji' in ledger_html and 'Karantina' in ledger_html and 'Isi kemasan historis: 8 biji' in ledger_html
        assert 'SMOKE-BATCH' in ledger_html and 'bukan saldo akhir atau saldo berjalan' in ledger_html
        assert 'wholesale_price' not in ledger_html and 'retail_price' not in ledger_html
        with opener.open(web+f'/app/stores/{store}/inventory/ledger/export?'+web_ledger_query) as response:
            ledger_csv=list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'))))
            assert response.headers['Content-Type'].startswith('text/csv') and 'no-store' in response.headers['Cache-Control']
        assert len(ledger_csv)==2 and ledger_csv[1][2]==batch['id'] and ledger_csv[1][7]=='8'
        assert ledger_csv[1][13]=='quarantined' and ledger_csv[1][14]=='16'
        assert ledger_csv[1][12]==history['data'][0]['source_id']
        assert api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data'][0]['stock_revision']==batch['stock_revision']
        ledger_export_query=urlencode({'from':ledger_day+'T00:00:00+07:00','to':(datetime.fromisoformat(ledger_day)+timedelta(days=1)).date().isoformat()+'T00:00:00+07:00'})
        api(base,'GET',f'stores/{store}/inventory/ledger/export?'+ledger_export_query,token=owner,expected=403)
        stock_html=opener.open(web+f'/app/stores/{store}/operations').read().decode()
        assert 'Riwayat batch ini' in stock_html and ('batch_id='+batch['id']) in stock_html
        expiry_query=urlencode({'expires_from':'2030-01-01','expires_to':'2030-01-01','with_balance':1,'q':'SMOKE-BATCH'})
        expiry_catalog=api(base,'GET',f'stores/{store}/inventory/batches?'+expiry_query,token=lead_token)
        assert expiry_catalog['store']['id']==store and expiry_catalog['total']==1 and expiry_catalog['data'][0]['id']==batch['id']
        assert expiry_catalog['data'][0]['pack_size']==8 and expiry_catalog['data'][0]['stock_revision']==batch['stock_revision']
        assert 'wholesale_price' not in expiry_catalog['data'][0] and 'retail_price' not in expiry_catalog['data'][0]
        assert api(base,'GET',f'stores/{store}/inventory/batches?expires_to=2029-12-31',token=lead_token)['total']==0
        with opener.open(web+f'/app/stores/{store}/inventory/batch-expiry?'+expiry_query) as response:
            expiry_html=response.read().decode()
            assert 'no-store' in response.headers['Cache-Control']
        assert 'Penelusuran kedaluwarsa batch' in expiry_html and 'SMOKE-BATCH' in expiry_html and 'Karantina 16' in expiry_html
        assert 'Telusuri kedaluwarsa batch' in stock_html


        api(base,'GET',f'stores/{store}/inventory/ledger',token=owner,expected=403)
        warehouse=api(base,'GET',warehouse_path+'/'+order_id,token=owner)['data']
        assert not warehouse['can_dispatch'] and warehouse['order']['lines'][0]['remaining_packs']==0
        assert warehouse['order']['lines'][0]['sent_packs']==20 and warehouse['batches']==[] and len(warehouse['shipments'])==1
        api(base,'POST',f'orders/{order_id}/shipments',dispatch,owner,201)
        confirmed = api(base, 'GET', f'orders/{order_id}/shipments', token=lead_token)['data'][0]
        assert confirmed['received_at'] and confirmed['lines'][0]['good_packs'] == 18 and confirmed['lines'][0]['damaged_packs'] == 2
        html = opener.open(web + f'/app/stores/{store}/operations').read().decode()
        assert '144' in html and 'Karantina: 16' in html
        api(base, 'GET', f'stores/{store}/inventory/batches', token=owner, expected=403)
        # Stock controls: real web processing/review, manager proposes with an observed revision.
        manager = {'name':'Smoke Manager','email':'manager@smoke.test','password':PASSWORD,'role':'store_manager','store_ids':[store]}
        api(base, 'POST', f'organizations/{org}/users', manager, owner, 201)
        manager_token = api(base, 'POST', 'auth/login', {'email':manager['email'],'password':PASSWORD,'device_name':'smoke'})['token']
        control_path = f'/app/stores/{store}/stock-control'
        html = opener.open(web + control_path).read().decode()
        assert 'Buka kemasan beku' in html and 'Siap jual' in html
        csrf = re.search(r'name="_token" value="([^"]+)"', html).group(1)
        process_path = control_path + '/processes'
        opening = {'_token':csrf,'operation_id':str(uuid.uuid4()),'batch_id':batch['id'],'expected_revision':batch['stock_revision'],'kind':'open','quantity_packs':'1','reason':'Physical pack opening'}
        web_post(process_path, opening).read()
        web_post(process_path, opening).read()
        batch = api(base, 'GET', f'stores/{store}/inventory/batches', token=lead_token)['data'][0]
        assert batch['usable_pieces'] == 136 and batch['opened_pieces'] == 8
        cooking = {'_token':csrf,'operation_id':str(uuid.uuid4()),'batch_id':batch['id'],'expected_revision':batch['stock_revision'],'kind':'cook','input_pieces':'6','output_pieces':'5','reason':'Five actual outputs and one processing loss'}
        web_post(process_path, cooking).read()
        batch = api(base, 'GET', f'stores/{store}/inventory/batches', token=lead_token)['data'][0]
        assert batch['opened_pieces'] == 2 and batch['ready_pieces'] == 5 and batch['waste_pieces'] == 1
        count = api(base, 'POST', f'stores/{store}/inventory/counts', {'operation_id':str(uuid.uuid4()),'batch_id':batch['id'],'expected_revision':batch['stock_revision'],'purpose':'waste','bucket':'opened','counted_pieces':1,'reason':'One physically lost opened piece'}, manager_token, 201)['data']
        before_review = api(base, 'GET', f'stores/{store}/inventory/batches', token=lead_token)['data'][0]
        assert before_review['opened_pieces'] == 2
        api(base, 'POST', f'inventory/counts/{count["id"]}/approve', {'operation_id':str(uuid.uuid4()),'version':1,'review_note':'Self review must fail'}, manager_token, expected=403)
        html = opener.open(web + control_path).read().decode()
        approval_path = control_path + f'/counts/{count["id"]}/approve'
        approval = fields(html, approval_path); approval['review_note'] = 'Rechecked physical stock by partner lead'
        web_post(approval_path, approval).read()
        web_post(approval_path, approval).read()
        batch = api(base, 'GET', f'stores/{store}/inventory/batches', token=lead_token)['data'][0]
        assert batch['opened_pieces'] == 1 and batch['ready_pieces'] == 5 and batch['waste_pieces'] == 2 and batch['available_pieces'] == 142
        stale = api(base, 'POST', f'stores/{store}/inventory/counts', {'operation_id':str(uuid.uuid4()),'batch_id':batch['id'],'expected_revision':batch['stock_revision'],'purpose':'stocktake','bucket':'opened','counted_pieces':0,'reason':'Physical recount'}, manager_token, 201)['data']
        api(base, 'POST', f'stores/{store}/inventory/processes', {'operation_id':str(uuid.uuid4()),'batch_id':batch['id'],'expected_revision':batch['stock_revision'],'kind':'cook','input_pieces':1,'output_pieces':1,'reason':'Movement after count snapshot'}, manager_token, 201)
        api(base, 'POST', f'inventory/counts/{stale["id"]}/approve', {'operation_id':str(uuid.uuid4()),'version':1,'review_note':'Stale review must fail'}, lead_token, expected=409)
        html = opener.open(web + control_path).read().decode()
        assert 'Snapshot kedaluwarsa' in html
        # Configure threshold through the real manager web session (CSRF enforced).
        min_path = f'/app/stores/{store}/stock-minimums'
        html = opener.open(web + min_path).read().decode()
        config_path = min_path + '/' + local_product_id
        minimum = fields(html, config_path)
        minimum.update({'minimum_pieces':'140','reason':'Store buffer reviewed by manager'})
        web_post(config_path, minimum).read()
        web_post(config_path, minimum).read()
        observed = api(base, 'GET', f'stores/{store}/inventory/minimums', token=lead_token)
        assert observed['data'][0]['minimum_pieces'] == 140 and observed['data'][0]['version'] == 1
        assert observed['data'][0]['available_pieces'] == 142 and not observed['data'][0]['is_low']
        api(base, 'GET', f'stores/{store}/inventory/minimums', token=owner, expected=403)
        minimum_detail_path=f'stores/{store}/inventory/minimums/{local_product_id}'
        exact=api(base,'GET',minimum_detail_path+'?page=2&low_only=1&q=not-found',token=manager_token)
        assert exact['can_manage'] and exact['store']['id']==store and exact['data']['product_id']==local_product_id
        assert exact['data']['version']==1 and exact['data']['minimum_pieces']==140
        assert 'wholesale_price' not in exact['data'] and 'retail_price' not in exact['data']
        api(base,'GET',minimum_detail_path,token=owner,expected=403)
        # Native clients use an Inventory-owned capability endpoint, without Ordering coupling.
        control_context = api(base, 'GET', f'stores/{store}/inventory/control-context', token=manager_token)['data']
        assert control_context['can_process'] and control_context['can_propose'] and not control_context['can_review']
        assert api(base, 'GET', f'stores/{store}/inventory/control-context', token=lead_token)['data']['can_review']
        api(base, 'GET', f'stores/{store}/inventory/control-context', token=owner, expected=403)
        # Real cashier browser session: durable draft, cash sale/reprint, manager expense and closing.
        cashier = {'name':'Smoke Cashier','email':'cashier@smoke.test','password':PASSWORD,'role':'cashier','store_ids':[store]}
        api(base, 'POST', f'organizations/{org}/users', cashier, owner, 201)
        cashier_token = api(base, 'POST', 'auth/login', {'email':cashier['email'],'password':PASSWORD,'device_name':'smoke'})['token']
        api(base, 'GET', f'stores/{store}/inventory/control-context', token=cashier_token, expected=403)
        api(base,'GET',f'stores/{store}/inventory/ledger',token=cashier_token,expected=403)
        api(base,'GET',f'stores/{store}/inventory/ledger/export?'+ledger_export_query,token=cashier_token,expected=403)
        assert not api(base,'GET',minimum_detail_path,token=cashier_token)['can_manage']
        opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        html = opener.open(web + '/login').read().decode()
        csrf = re.search(r'name="_token" value="([^"]+)"', html).group(1)
        opener.open(Request(web + '/login', data=urlencode({'_token':csrf,'email':cashier['email'],'password':PASSWORD}).encode())).read()
        try:
            opener.open(web+f'/app/stores/{store}/inventory/ledger').read()
            raise AssertionError('Cashier web session must not read staff ledger')
        except HTTPError as error:
            assert error.code==403
        cashier_expiry=opener.open(web+f'/app/stores/{store}/inventory/batch-expiry?'+expiry_query).read().decode()
        assert 'SMOKE-BATCH' in cashier_expiry and 'wholesale_price' not in cashier_expiry
        pos_path = f'/app/stores/{store}/pos'
        html = opener.open(web + pos_path).read().decode()
        open_path = pos_path + '/shifts'; opening = fields(html, open_path)
        opening.update({'device_name':'Smoke Terminal','opening_cash':'100000'})
        html = web_post(open_path, opening).read().decode()
        cart_path = pos_path + '/intents'; cart = fields(html, cart_path)
        cart.update({'lines[0][product_id]':local_product_id,'lines[0][unit]':'piece','lines[0][quantity]':'4','lines[0][stock]':batch['id']+':ready'})
        response = web_post(cart_path, cart); html = response.read().decode(); intent_id = response.url.rsplit('/',1)[1]
        assert 'Tinjau pembayaran' in html and 'Total Rp 12000' in html
        assert web_post(cart_path, cart).url.endswith(intent_id)
        pay_path = f'/app/pos/intents/{intent_id}/pay'; payment = fields(html, pay_path); payment.update({'cash_received':'20000','payment_method':'cash'})
        response = web_post(pay_path, payment); html=response.read().decode(); sale_id=response.url.rsplit('/',1)[1]
        assert '12.000' in html and '8.000' in html and 'Cetak melalui browser' in html
        assert web_post(pay_path,payment).url.endswith(sale_id)
        sale = api(base,'GET',f'sales/{sale_id}',token=cashier_token)['data']
        assert sale['total_amount']==12000 and sale['change_amount']==8000
        sale_time=datetime.fromisoformat(sale['sold_at'].replace('Z','+00:00'))
        history_query=urlencode({'from':(sale_time-timedelta(seconds=1)).isoformat(),'to':(sale_time+timedelta(seconds=1)).isoformat(),'q':sale['number']})
        history=api(base,'GET',f'stores/{store}/sales?{history_query}',token=cashier_token)
        assert history['scope']=='own' and history['store']['id']==store and history['total']==1
        assert history['data'][0]['id']==sale_id and history['data'][0]['total_amount']==12000
        history=api(base,'GET',f'stores/{store}/sales?{history_query}',token=manager_token)
        assert history['scope']=='store' and history['data'][0]['id']==sale_id
        api(base,'GET',f'stores/{store}/sales?{history_query}',token=owner,expected=403)
        receipt_day=sale_time.astimezone(timezone(timedelta(hours=7))).date().isoformat()
        web_receipt_query=urlencode({'from_day':receipt_day,'to_day':receipt_day,'q':sale['number']})
        with opener.open(web+f'/app/stores/{store}/pos/sales?'+web_receipt_query) as response:
            receipt_history_html=response.read().decode()
            assert 'no-store' in response.headers['Cache-Control']
        assert 'Cakupan: struk saya.' in receipt_history_html and sale['number'] in receipt_history_html and 'Rp 12.000' in receipt_history_html
        assert ('/app/pos/sales/'+sale_id) in receipt_history_html
        all_history_html=opener.open(web+f'/app/stores/{store}/pos/sales?'+urlencode({'q':sale['number']})).read().decode()
        assert 'Periode diterapkan: seluruh riwayat.' in all_history_html and sale['number'] in all_history_html

        current=api(base,'GET',f'stores/{store}/inventory/batches',token=cashier_token)['data'][0]
        assert current['ready_pieces']==2 and current['quarantined_pieces']==16
        warning = api(base,'GET',f'stores/{store}/inventory/minimums?low_only=1',token=cashier_token)
        assert warning['total']==1 and warning['data'][0]['available_pieces']==138 and warning['data'][0]['shortfall_pieces']==2
        assert not warning['can_manage'] and 'wholesale_price' not in warning['data'][0]
        api(base,'POST',f'stores/{store}/inventory/minimums/{local_product_id}',{'operation_id':str(uuid.uuid4()),'version':1,'minimum_pieces':None,'reason':'Cashier must not configure'},cashier_token,expected=403)
        html=opener.open(web+min_path+'?low_only=1').read().decode()
        assert 'Kekurangan 2 biji' in html and 'Simpan ambang' not in html
        entries=api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']
        opener.open(web+f'/app/pos/sales/{sale_id}').read()
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==entries
        shift=api(base,'GET',f'shifts/{sale["cash_shift_id"]}',token=cashier_token)['data']
        assert shift['expected_cash']==112000 and shift['cash_sales']==12000
        assert not shift['can_record_movement']
        manager_shift=api(base,'GET',f'shifts/{shift["id"]}',token=manager_token)['data']
        assert manager_shift['can_record_movement']
        assert api(base,'GET',f'stores/{store}/shifts?page=1',token=manager_token)['data'][0]['id']==shift['id']
        movement_body={'operation_id':str(uuid.uuid4()),'version':shift['version'],'kind':'out','amount':2000,'category':'Operations','reason':'Physical drawer expense'}
        movement=api(base,'POST',f'shifts/{shift["id"]}/cash-movements',movement_body,manager_token,201)['data']
        assert movement['shift']['expected_cash']==110000 and movement['shift']['cash_sales']==12000
        html=opener.open(web+f'/app/pos/shifts/{shift["id"]}').read().decode()
        close_path=f'/app/pos/shifts/{shift["id"]}/close'; closing=fields(html,close_path)
        closing.update({'counted_cash':'109000','closing_note':'Physical count differs by one thousand'})
        html=web_post(close_path,closing).read().decode()
        assert 'closed' in html and '-1000' in html
        shift=api(base,'GET',f'shifts/{shift["id"]}',token=cashier_token)['data']
        assert shift['expected_cash']==110000 and shift['difference_cash']==-1000 and shift['cash_sales']==12000
        assert not api(base,'GET',f'shifts/{shift["id"]}',token=manager_token)['data']['can_record_movement']
        replay=api(base,'POST',f'shifts/{shift["id"]}/cash-movements',movement_body,manager_token,201)['data']
        assert replay['movement']['id']==movement['movement']['id']
        current_shift=api(base,'GET',f'shifts/{shift["id"]}',token=manager_token)['data']
        assert current_shift['status']=='closed' and len(current_shift['movements'])==1
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==entries
        assert api(base,'GET',f'stores/{store}/pos/context',token=cashier_token)['data']['shift'] is None
        # Cross-store cash reporting and CSV use the same stored sale, excluding cash expenses.
        wib = timezone(timedelta(hours=7))
        sold_day = datetime.fromisoformat(sale['sold_at'].replace('Z','+00:00')).astimezone(wib).replace(hour=0,minute=0,second=0,microsecond=0)
        period = {'from':sold_day.isoformat(),'to':(sold_day+timedelta(days=1)).isoformat()}
        report_path = f'organizations/{org}/reports/cash-sales'
        query = urlencode(period)
        report = api(base,'GET',report_path+'?'+query,token=lead_token)['data']
        assert report['totals']['net_sales']==12000 and report['totals']['transaction_count']==1
        assert sum(row['net_sales'] for row in report['by_store'])==12000
        product_report = api(base,'GET',report_path+'/products?'+query,token=manager_token)
        assert product_report['data'][0]['sold_pieces']==4 and product_report['data'][0]['gross_sales']==12000
        for denied_token in [cashier_token,owner]:
            for suffix in ['', '/products', '/export']:
                api(base,'GET',report_path+suffix+'?'+query,token=denied_token,expected=403)
        with urlopen(Request(base+'/api/v1/'+report_path+'/export?'+query,headers={'Authorization':'Bearer '+lead_token,'Accept':'text/csv'})) as response:
            rows=list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'))))
            assert 'no-store' in response.headers['Cache-Control']
        assert len(rows)==2 and rows[1][0]==sale['number'] and rows[1][8]=='12000'
        cashier_browser=opener
        opener=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        html=opener.open(web+'/login').read().decode()
        csrf=re.search(r'name="_token" value="([^"]+)"',html).group(1)
        opener.open(Request(web+'/login',data=urlencode({'_token':csrf,'email':lead['email'],'password':PASSWORD}).encode())).read()
        web_period=urlencode({'from_day':sold_day.date().isoformat(),'to_day':sold_day.date().isoformat()})
        manager_history_html=opener.open(web+f'/app/stores/{store}/pos/sales?'+web_receipt_query).read().decode()
        assert 'seluruh kasir toko yang diizinkan' in manager_history_html and sale['number'] in manager_history_html
        html=opener.open(web+'/app/'+report_path+'?'+web_period).read().decode()
        assert 'Rp 12.000' in html and '4 biji' in html
        with opener.open(web+'/app/'+report_path+'/export?'+web_period) as response:
            assert response.headers['Content-Type'].startswith('text/csv')
            assert sale['number'] in response.read().decode('utf-8-sig')

        # Native threshold mutation: explicit zero vs null, version conflict and immutable replay.
        ledger_before=api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']
        zero={'operation_id':str(uuid.uuid4()),'version':1,'minimum_pieces':0,'reason':'Explicit zero reviewed on native'}
        assert api(base,'POST',minimum_detail_path,zero,manager_token)['data']['version']==2
        disabled={'operation_id':str(uuid.uuid4()),'version':2,'minimum_pieces':None,'reason':'Explicitly stop monitoring'}
        api(base,'POST',minimum_detail_path,disabled,manager_token)
        replay=api(base,'POST',minimum_detail_path,zero,manager_token)['data']
        assert replay['version']==2 and replay['minimum_pieces']==0
        current=api(base,'GET',minimum_detail_path,token=manager_token)
        assert current['data']['version']==3 and current['data']['minimum_pieces'] is None
        api(base,'POST',minimum_detail_path,{**zero,'operation_id':str(uuid.uuid4())},manager_token,expected=409)
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before
        api(base,'POST',f'stores/{source}/inventory/intakes',intake_request,owner,201)
        assert api(base,'GET',f'stores/{source}/inventory/batches',token=owner)['data'][0]['usable_pieces']==0
        # Read-only scoped native audit: filters cannot expose operational data through onboarding.
        audit_path=f'organizations/{org}/audit'
        audit_total=api(base,'GET',audit_path,token=lead_token)['total']
        audit_query=urlencode({**period,'action':'sale.completed','subject_id':sale_id,'actor_id':sale['cashier_id']})
        history=api(base,'GET',audit_path+'?'+audit_query,token=lead_token)
        assert history['organization']['id']==org and history['visibility']=='organization' and history['total']==1
        assert history['data'][0]['subject_id']==sale_id and history['data'][0]['occurred_at'].endswith('+00:00')
        assert json.loads(history['data'][0]['after'])['total_amount']==12000
        central_audit=api(base,'GET',audit_path+'?'+audit_query,token=owner)
        assert central_audit['visibility']=='onboarding' and central_audit['data']==[]
        for denied in [manager_token,cashier_token]:
            api(base,'GET',audit_path,token=denied,expected=403)
        web_audit_query=urlencode({'from_day':sold_day.date().isoformat(),'to_day':sold_day.date().isoformat(),'action':'sale.completed','subject_id':sale_id,'actor_id':sale['cashier_id']})
        with opener.open(web+'/app/'+audit_path+'?'+web_audit_query) as response:
            html=response.read().decode()
            assert 'no-store' in response.headers['Cache-Control']
        assert 'Jejak audit' in html and 'Perubahan sebelum / sesudah' in html and '12000' in html and sale_id in html
        central_browser=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        central_login=central_browser.open(web+'/login').read().decode()
        central_csrf=re.search(r'name="_token" value="([^"]+)"',central_login).group(1)
        central_browser.open(Request(web+'/login',data=urlencode({'_token':central_csrf,'email':'owner@smoke.test','password':PASSWORD}).encode())).read()
        central_html=central_browser.open(web+'/app/'+audit_path+'?'+web_audit_query).read().decode()
        assert 'hanya data onboarding' in central_html and 'Tidak ada audit pada filter ini.' in central_html
        assert 'wholesale_price' not in central_html
        with opener.open(web+'/app/'+audit_path+'/export?'+web_audit_query) as response:
            audit_csv=list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'))))
            assert response.headers['Content-Type'].startswith('text/csv') and 'no-store' in response.headers['Cache-Control']
        assert len(audit_csv)==2 and audit_csv[1][2]=='organization' and audit_csv[1][5]=='sale.completed'
        assert json.loads(audit_csv[1][8])['total_amount']==12000
        with central_browser.open(web+'/app/'+audit_path+'/export?'+web_audit_query) as response:
            limited_csv=list(csv.reader(io.StringIO(response.read().decode('utf-8-sig'))))
        assert len(limited_csv)==1 and limited_csv[0][0]=='audit_id'
        try:
            central_browser.open(web+f'/app/stores/{store}/pos/sales?'+web_receipt_query).read()
            raise AssertionError('Central onboarding must not read partner receipts')
        except HTTPError as error:
            assert error.code==403

        assert api(base,'GET',audit_path,token=lead_token)['total']==audit_total
        # Separate order: supplier web proposes reduction, recipient web accepts; no stock posting.
        change_order=api(base,'POST',f'stores/{store}/orders',{'operation_id':str(uuid.uuid4()),'delivery_mode':'pickup','lines':[{'supplier_product_id':supplier_id,'local_product_id':local_product_id,'quantity_packs':2}]},lead_token,201)['data']
        change_id=change_order['id']
        change_order=api(base,'POST',f'orders/{change_id}/approve',{'operation_id':str(uuid.uuid4()),'version':change_order['version']},lead_token)['data']
        proposal_path=f'/app/orders/{change_id}/changes'
        proposal_html=central_browser.open(web+proposal_path).read().decode()
        proposal=fields(proposal_html,proposal_path)
        proposal.update({'reason':'Supply only one confirmed pack','lines[0][quantity_packs]':'1'})
        central_browser.open(Request(web+proposal_path,data=urlencode(proposal).encode())).read()
        proposal_history=api(base,'GET',f'orders/{change_id}/changes',token=lead_token)
        assert proposal_history['order']['total_amount']==36000 and proposal_history['change_pending']
        request_id=proposal_history['data'][0]['id']
        accept_path=f'/app/orders/{change_id}/changes/{request_id}/approve'
        accept_html=opener.open(web+proposal_path).read().decode()
        web_post(accept_path,fields(accept_html,accept_path)).read()
        accepted=api(base,'GET',f'orders/{change_id}/changes',token=lead_token)
        assert accepted['data'][0]['status']=='approved' and not accepted['change_pending']
        assert accepted['order']['lines'][0]['id']==change_order['lines'][0]['id']
        assert accepted['order']['lines'][0]['quantity_packs']==1 and accepted['order']['total_amount']==18000
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before
        # Actual discrepancy case and two-party plan approval, never stock/financial execution.
        before_case_batches=api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']
        case_path=f'/app/orders/{order_id}/shipments/{shipment["id"]}/discrepancy'
        case_api=f'orders/{order_id}/shipments/{shipment["id"]}/discrepancy'
        before_case_order=api(base,'GET',f'orders/{order_id}',token=lead_token)['data']
        case_html=opener.open(web+case_path).read().decode()
        report=fields(case_html,case_path); report.update({'reason':'Two damaged packs from actual receipt','reference':'Isolated physical inspection'})
        web_post(case_path,report).read()
        case_data=api(base,'GET',case_api,token=lead_token)
        assert case_data['case']['version']==1 and case_data['snapshot']['problem_packs']==2
        assert case_data['snapshot']['lines'][0]['damaged_pieces']==16 and case_data['snapshot']['lines'][0]['missing_packs']==0
        proposal_path=case_path+'/resolutions'
        case_html=central_browser.open(web+case_path).read().decode()
        case_proposal=fields(case_html,proposal_path); case_proposal.update({'type':'replacement','proposal':'Replacement plan; physical execution still outstanding'})
        central_browser.open(Request(web+proposal_path,data=urlencode(case_proposal).encode())).read()
        case_data=api(base,'GET',case_api,token=lead_token)
        resolution_id=case_data['pending_resolution']['id']
        confirm_path=proposal_path+'/'+resolution_id+'/confirm'
        case_html=opener.open(web+case_path).read().decode()
        confirmation=fields(case_html,confirm_path); confirmation['reason']='Partner confirms plan, no stock receipt implied'
        web_post(confirm_path,confirmation).read()
        case_data=api(base,'GET',case_api,token=lead_token)
        assert case_data['case']['status']=='plan_confirmed' and case_data['pending_resolution'] is None
        note_path=case_path+'/notes'
        case_html=central_browser.open(web+case_path).read().decode()
        follow_up=fields(case_html,note_path); follow_up['message']='Approved plan still awaiting physical execution'
        central_browser.open(Request(web+note_path,data=urlencode(follow_up).encode())).read()
        final_case=api(base,'GET',case_api,token=lead_token)
        assert final_case['case']['version']==4 and final_case['data'][0]['side']=='supplier'
        assert api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']==before_case_batches
        assert api(base,'GET',f'orders/{order_id}',token=lead_token)['data']['version']==before_case_order['version']
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before
        # Execute confirmed replacement with actual inventory posting, using real CSRF forms.
        replacement_path=case_path+'/replacements'
        replacement_api=case_api+'/replacements'
        replacement_context=api(base,'GET',replacement_api,token=owner)
        assert replacement_context['execution_status']=='ready' and replacement_context['can_dispatch']
        original_line=replacement_context['quota'][0]['shipment_line_id']
        source_product=replacement_context['quota'][0]['supplier_product_id']
        api(base,'POST',f'stores/{source}/inventory/intakes',{'operation_id':str(uuid.uuid4()),'product_id':source_product,'batch_code':'REPLACEMENT-STOCK','expires_on':'2030-01-01','quantity_packs':2,'note':'Physical replacement stock replenishment'},owner,201)
        replacement_context=api(base,'GET',replacement_api,token=owner)
        source_batch=next(b for b in replacement_context['batches'] if b['product_id']==source_product and b['pack_size']==8 and b['usable_pieces']>=16)
        source_before=source_batch['usable_pieces']
        target_before=sum(b['usable_pieces'] for b in before_case_batches)
        damaged_before=sum(b['quarantined_pieces'] for b in before_case_batches)
        replacement_html=central_browser.open(web+replacement_path).read().decode()
        dispatch_form=fields(replacement_html,replacement_path)
        dispatch_form.update({'handler':'Replacement courier','note':'Two physical replacements','lines[0][original_shipment_line_id]':original_line,'lines[0][batch_id]':source_batch['id'],'lines[0][quantity_packs]':'2'})
        central_browser.open(Request(web+replacement_path,data=urlencode(dispatch_form).encode())).read()
        replacement_context=api(base,'GET',replacement_api,token=lead_token)
        assert replacement_context['execution_status']=='in_transit' and replacement_context['quota'][0]['remaining_packs']==0
        replacement_id=replacement_context['data'][0]['id']
        receipt_url=replacement_path+'/'+replacement_id+'/receive'
        replacement_html=opener.open(web+replacement_path).read().decode()
        replacement_receipt=fields(replacement_html,receipt_url)
        replacement_receipt.update({'lines[0][good_packs]':'2','lines[0][damaged_packs]':'0','note':'Replacement physically received'})
        web_post(receipt_url,replacement_receipt).read()
        original_receipt={'operation_id':replacement_receipt['operation_id'],'version':replacement_receipt['version'],'note':replacement_receipt['note'],'lines':[{'replacement_line_id':replacement_receipt['lines[0][replacement_line_id]'],'good_packs':'2','damaged_packs':'0'}]}
        api(base,'POST',replacement_api+'/'+replacement_id+'/receive',original_receipt,lead_token)
        final_replacement=api(base,'GET',replacement_api,token=lead_token)
        assert final_replacement['execution_status']=='fulfilled' and final_replacement['quota'][0]['replacement_good_packs']==2
        source_after=next(b for b in api(base,'GET',f'stores/{source}/inventory/batches',token=owner)['data'] if b['id']==source_batch['id'])
        target_after=api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']
        assert source_after['usable_pieces']==source_before-16
        assert sum(b['usable_pieces'] for b in target_after)==target_before+16
        assert sum(b['quarantined_pieces'] for b in target_after)==damaged_before
        assert api(base,'GET',f'orders/{order_id}',token=lead_token)['data']['version']==before_case_order['version']
        assert api(base,'GET',f'stores/{store}/inventory/ledger?source_type=replacement_receipt',token=manager_token)['data'][0]['quantity_pieces']==16
        ledger_before+=1
        # Disposable account: real web CSRF reactivation, stale version and old-token denial.
        member=api(base,'POST',f'organizations/{org}/users',{'name':'Returning staff','email':'returning@smoke.test','password':PASSWORD,'role':'cashier','store_ids':[store]},lead_token,201)['data']
        member_path=f'organizations/{org}/users/{member["id"]}'
        old_member_token=api(base,'POST','auth/login',{'email':member['email'],'password':PASSWORD,'device_name':'before-disable'})['token']
        api(base,'POST',member_path+'/deactivate',{},lead_token,204)
        api(base,'GET','auth/me',token=old_member_token,expected=401)
        api(base,'POST','auth/login',{'email':member['email'],'password':PASSWORD,'device_name':'disabled-denied'},expected=422)
        review_path='/app/'+member_path+'/reactivation'
        reactivate_path='/app/'+member_path+'/reactivate'
        with opener.open(web+review_path) as response:
            member_html=response.read().decode()
            assert 'no-store' in response.headers['Cache-Control']
        activation=fields(member_html,reactivate_path); activation['reason']='Reviewed return to work in isolated smoke'
        assert activation['version']=='2'
        web_post(reactivate_path,activation).read()
        replay={key:activation[key] for key in ['operation_id','version','reason']}
        assert api(base,'POST',member_path+'/reactivate',replay,lead_token)['data']['user']['access_version']==3
        api(base,'GET','auth/me',token=old_member_token,expected=401)
        new_member_token=api(base,'POST','auth/login',{'email':member['email'],'password':PASSWORD,'device_name':'after-reactivation'})['token']
        assert api(base,'GET','stores',token=new_member_token)['data'][0]['id']==store
        member_context=api(base,'GET',member_path+'/reactivation',token=lead_token)['data']
        assert member_context['user']['active'] and not member_context['can_reactivate']
        assert api(base,'GET',audit_path+'?'+urlencode({'action':'user.reactivated','subject_id':str(member['id'])}),token=lead_token)['total']==1
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before
        api(base,'POST','auth/logout',{},new_member_token,204)
        # Respect the unchanged180/min per-user business limiter before the second workflow group.
        print('Pacing isolated HTTP checks for the next rate-limit window.',flush=True)
        time.sleep(60)
        # DL12/14/17: revisions, effective prices, private images, bundle stock and contract consent.
        new_source=api(base,'POST',f'organizations/{center}/products',{'sku':'UDANG-8','name':'Smoke Udang','condition':'frozen','pack_size':8,'portion_size':4,'wholesale_price':24000,'retail_price':4000},owner,201)['data']
        new_local=api(base,'POST',f'organizations/{org}/products',{'sku':'LOCAL-UDANG8','name':'Smoke Local Udang','condition':'frozen','pack_size':8,'portion_size':4,'wholesale_price':24000,'retail_price':4000},lead_token,201)['data']
        original=api(base,'GET',f'orders/{order_id}',token=lead_token)['data']
        revision_path=f'/app/orders/{order_id}/revisions'
        revision_html=opener.open(web+revision_path).read().decode()
        proposal=fields(revision_html,revision_path)
        proposal.update({'existing[0][quantity_packs]':'20','additions[0][supplier_product_id]':new_source['id'],'additions[0][local_product_id]':new_local['id'],'additions[0][quantity_packs]':'3','reason':'Add shrimp after actual initial shipment'})
        preview_html=web_post(revision_path,proposal).read().decode()
        assert '432000' in preview_html and 'Konfirmasi ajukan revisi' in preview_html
        assert api(base,'GET',f'orders/{order_id}/revisions',token=lead_token)['total']==0
        confirm_revision=revision_path+'/confirm'
        web_post(confirm_revision,fields(preview_html,confirm_revision)).read()
        revised=api(base,'GET',f'orders/{order_id}/revisions',token=lead_token)
        assert revised['pending']['after']['total_amount']==432000
        revision_id=revised['pending']['id'];accept_revision=revision_path+'/'+revision_id+'/approve'
        revision_html=central_browser.open(web+revision_path).read().decode()
        central_browser.open(Request(web+accept_revision,data=urlencode(fields(revision_html,accept_revision)).encode())).read()
        revised=api(base,'GET',f'orders/{order_id}',token=lead_token)['data']
        assert len(revised['lines'])==2 and revised['total_amount']==432000
        new_line=next(l for l in revised['lines'] if l['supplier_product_id']==new_source['id'])
        new_stock=api(base,'POST',f'stores/{source}/inventory/intakes',{'operation_id':str(uuid.uuid4()),'product_id':new_source['id'],'batch_code':'UDANG-NEW','expires_on':'2030-01-01','quantity_packs':3,'note':'Actual shrimp stock'},owner,201)['data']
        extra_shipment=api(base,'POST',f'orders/{order_id}/shipments',{'operation_id':str(uuid.uuid4()),'version':revised['version'],'handler':'Shrimp dispatch','lines':[{'order_line_id':new_line['id'],'allocations':[{'batch_id':new_stock['id'],'quantity_packs':3}]}]},owner,201)['data']
        api(base,'POST',f'shipments/{extra_shipment["shipment"]["id"]}/receive',{'operation_id':str(uuid.uuid4()),'version':extra_shipment['order']['version'],'lines':[{'shipment_line_id':extra_shipment['shipment']['lines'][0]['id'],'good_packs':3,'damaged_packs':0}]},lead_token)
        stocks=api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']
        old_batch=next(b for b in stocks if b['product_id']==local_product_id and b['usable_pieces']>=8)
        new_batch=next(b for b in stocks if b['product_id']==new_local['id'])
        for physical in [old_batch,new_batch]:
            api(base,'POST',f'stores/{store}/inventory/processes',{'operation_id':str(uuid.uuid4()),'batch_id':physical['id'],'expected_revision':physical['stock_revision'],'kind':'open','quantity_packs':1,'reason':'Open actual pack for recipe components'},lead_token,201)
        today=(datetime.now(timezone.utc)+timedelta(hours=7)).date().isoformat()
        config_path=f'/app/organizations/{org}/products/{new_local["id"]}/configuration'
        config_html=opener.open(web+config_path).read().decode();price_path=config_path+'/prices'
        price_form=fields(config_html,price_path);price_form.update({'channel':'retail','starts_on':today,'ends_on':today,'price':'5000','reason':'Reviewed price period'})
        web_post(price_path,price_form).read()
        assert next(p for p in api(base,'GET',f'stores/{store}/catalog',token=cashier_token)['data'] if p['id']==new_local['id'])['retail_price']==5000
        png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jmWQAAAAASUVORK5CYII='
        api(base,'POST',f'organizations/{org}/products/{new_local["id"]}/image',{'operation_id':str(uuid.uuid4()),'version':0,'image_base64':png},lead_token)
        with opener.open(web+f'/app/organizations/{org}/products/{new_local["id"]}/image') as image_response:
            assert image_response.headers['Content-Type']=='image/png' and 'no-store' in image_response.headers['Cache-Control']
        assert api(base,'GET',f'stores/{store}/catalog/{new_local["id"]}/image-data',token=cashier_token)['image_base64']==png
        bundle=api(base,'POST',f'organizations/{org}/products',{'sku':'BUNDLE-AYAM-UDANG','name':'Smoke bundle2 ayam1 udang','condition':'frozen','pack_size':1,'portion_size':1,'wholesale_price':0,'retail_price':14000},lead_token,201)['data']
        api(base,'POST',f'organizations/{org}/products/{bundle["id"]}/recipe',{'operation_id':str(uuid.uuid4()),'version':0,'active':True,'components':[{'product_id':local_product_id,'pieces':2},{'product_id':new_local['id'],'pieces':1}]},lead_token)
        bundle_shift=api(base,'POST',f'stores/{store}/shifts',{'operation_id':str(uuid.uuid4()),'device_name':'Bundle terminal','opening_cash':0},cashier_token,201)['data']
        bundle_path=f'/app/stores/{store}/pos/bundles/{bundle["id"]}'
        bundle_html=cashier_browser.open(web+bundle_path).read().decode();bundle_form=fields(bundle_html,bundle_path)
        bundle_form.update({'quantity':'1','components[0][stock]':old_batch['id']+':opened','components[1][stock]':new_batch['id']+':opened'})
        bundle_response=cashier_browser.open(Request(web+bundle_path,data=urlencode(bundle_form).encode()));bundle_html=bundle_response.read().decode();bundle_intent=bundle_response.url.rsplit('/',1)[1]
        assert 'Total Rp 14000' in bundle_html
        bundle_pay=f'/app/pos/intents/{bundle_intent}/pay';bundle_payment=fields(bundle_html,bundle_pay);bundle_payment.update({'cash_received':'14000','payment_method':'cash'})
        bundle_response=cashier_browser.open(Request(web+bundle_pay,data=urlencode(bundle_payment).encode()));bundle_response.read();bundle_sale=bundle_response.url.rsplit('/',1)[1]
        actual_bundle=api(base,'GET',f'sales/{bundle_sale}',token=cashier_token)['data']
        assert actual_bundle['total_amount']==14000 and len(actual_bundle['lines'][0]['recipe_snapshot']['components'])==2
        stocks=api(base,'GET',f'stores/{store}/inventory/batches',token=lead_token)['data']
        assert next(b for b in stocks if b['id']==old_batch['id'])['opened_pieces']==old_batch['opened_pieces']+8-2
        assert next(b for b in stocks if b['id']==new_batch['id'])['opened_pieces']==new_batch['opened_pieces']+8-1
        current_shift=api(base,'GET',f'shifts/{bundle_shift["id"]}',token=cashier_token)['data']
        api(base,'POST',f'shifts/{bundle_shift["id"]}/close',{'operation_id':str(uuid.uuid4()),'version':current_shift['version'],'counted_cash':14000},cashier_token)
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before+7
        ledger_before+=7
        contract=api(base,'POST',f'organizations/{center}/contracts',{'operation_id':str(uuid.uuid4()),'partner_id':org,'title':'Reviewed partnership','mode':'buyout','starts_on':today,'ends_on':'2030-12-31','terms':'Agreed written terms','brand_terms':'Agreed branding','data_scope':'Onboarding only','facilities':'No automatic asset charges'},owner,201)['data']
        assert api(base,'GET',f'organizations/{org}/contracts',token=lead_token)['total']==0
        center_contract_path=f'/app/organizations/{center}/contracts'
        contract_html=central_browser.open(web+center_contract_path).read().decode();publish_path=center_contract_path+'/'+contract['id']+'/publish'
        central_browser.open(Request(web+publish_path,data=urlencode(fields(contract_html,publish_path)).encode())).read()
        partner_contract_path=f'/app/organizations/{org}/contracts';contract_html=opener.open(web+partner_contract_path).read().decode();accept_path=partner_contract_path+'/'+contract['id']+'/accept'
        consent=fields(contract_html,accept_path);consent.update({'consent':'1','consent_name':'Smoke partner director'})
        web_post(accept_path,consent).read()
        assert api(base,'GET',f'organizations/{org}/contracts',token=lead_token)['data'][0]['status']=='accepted'
        # DL13/15/16: real return quarantine, explicit invoice/credit/consignment and manual POS adjustments.
        finance_stock=api(base,'POST',f'stores/{source}/inventory/intakes',{'operation_id':str(uuid.uuid4()),'product_id':supplier_id,'batch_code':'FINANCE-RETURN','expires_on':'2030-01-01','quantity_packs':2,'note':'Actual return fixture stock'},owner,201)['data']
        fo=api(base,'POST',f'stores/{store}/orders',{'operation_id':str(uuid.uuid4()),'delivery_mode':'pickup','lines':[{'supplier_product_id':supplier_id,'local_product_id':local_product_id,'quantity_packs':2}]},lead_token,201)['data']
        for action,actor,extra in [('approve',lead_token,{}),('process',owner,{'source_store_id':source})]:
            fo=api(base,'POST',f'orders/{fo["id"]}/{action}',{'operation_id':str(uuid.uuid4()),'version':fo['version'],**extra},actor)['data']
        fd=api(base,'POST',f'orders/{fo["id"]}/shipments',{'operation_id':str(uuid.uuid4()),'version':fo['version'],'handler':'Actual finance shipment','lines':[{'order_line_id':fo['lines'][0]['id'],'allocations':[{'batch_id':finance_stock['id'],'quantity_packs':2}]}]},owner,201)['data']
        fr=api(base,'POST',f'shipments/{fd["shipment"]["id"]}/receive',{'operation_id':str(uuid.uuid4()),'version':fd['order']['version'],'lines':[{'shipment_line_id':fd['shipment']['lines'][0]['id'],'good_packs':1,'damaged_packs':1,'condition_note':'One pack physically damaged'}]},lead_token)['data']
        fp=f'orders/{fo["id"]}/shipments/{fd["shipment"]["id"]}/discrepancy'
        fc=api(base,'POST',fp,{'operation_id':str(uuid.uuid4()),'order_version':fr['order']['version'],'reason':'Actual damaged pack returned'},lead_token,201)['data']
        plan=api(base,'POST',fp+'/resolutions',{'operation_id':str(uuid.uuid4()),'version':1,'type':'return','proposal':'Return damaged pack to center quarantine; credit reviewed separately'},owner,201)['data']
        api(base,'POST',fp+'/resolutions/'+plan['resolution']['id']+'/confirm',{'operation_id':str(uuid.uuid4()),'version':2},lead_token)
        return_path='/app/'+fp+'/returns';return_html=opener.open(web+return_path).read().decode();return_form=fields(return_html,return_path)
        return_context=api(base,'GET',fp+'/returns',token=lead_token)
        return_form.update({'handler':'Physical return courier','lines[0][batch_id]':return_context['batches'][0]['id'],'lines[0][quantity_packs]':'1'})
        web_post(return_path,return_form).read()
        returned=api(base,'GET',fp+'/returns',token=owner)['data'][0];return_receive=return_path+'/'+returned['id']+'/receive'
        return_html=central_browser.open(web+return_path).read().decode();return_form=fields(return_html,return_receive);return_form['lines[0][received_packs]']='1'
        central_browser.open(Request(web+return_receive,data=urlencode(return_form).encode())).read()
        assert api(base,'GET',fp+'/returns',token=owner)['execution_status']=='fulfilled'
        bp=f'/app/orders/{fo["id"]}/billing';bh=central_browser.open(web+bp).read().decode()
        proposal={'_token':fields(bh,bp)['_token'],'action':'invoice','operation_id':str(uuid.uuid4()),'mode':'buyout','basis':'dispatched','shipment_ids[0]':fd['shipment']['id'],'due_on':'2030-12-31','discount_amount':'0','shipping_amount':'0','tax_basis_points':'1100','rounding':'half_up','note':'Explicit shipment basis, tax and due date for partner review'}
        central_browser.open(Request(web+bp,data=urlencode(proposal).encode())).read()
        fi=api(base,'GET',f'orders/{fo["id"]}/billing',token=lead_token)['invoices']['data'][0]
        assert fi['status']=='pending' and fi['total_amount']==39960
        bh=opener.open(web+bp).read().decode();web_post(bp,{'_token':fields(bh,bp)['_token'],'action':'invoice_accept','operation_id':str(uuid.uuid4()),'invoice_id':fi['id'],'version':fi['version'],'consent':'1','reason':'Explicit terms reviewed and accepted'}).read()
        fi=api(base,'POST',f'orders/{fo["id"]}/billing/invoices/{fi["id"]}/payments',{'operation_id':str(uuid.uuid4()),'version':2,'kind':'collection','method':'bank_transfer','amount':10000,'reference':'BANK-PARTIAL-1','paid_on':today,'verified':True,'note':'Actual bank receipt verified manually'},owner,201)['data']
        assert fi['outstanding_amount']==29960
        fi=api(base,'POST',f'orders/{fo["id"]}/billing/invoices/{fi["id"]}/credits',{'operation_id':str(uuid.uuid4()),'version':3,'case_id':fc['case']['id'],'base_amount':18000,'tax_amount':1980,'reason':'One physically returned pack at historical price'},owner,201)['data']
        fi=api(base,'POST',f'orders/{fo["id"]}/billing/invoices/{fi["id"]}/credits/{fi["credits"][0]["id"]}/accept',{'operation_id':str(uuid.uuid4()),'version':4,'consent':True,'reason':'Partner confirms returned-goods credit'},lead_token)['data']
        assert fi['outstanding_amount']==9980 and fi['credit_amount']==19980
        api(base,'GET',f'orders/{fo["id"]}/billing',token=cashier_token,expected=403)
        cc=api(base,'POST',f'organizations/{center}/contracts',{'operation_id':str(uuid.uuid4()),'partner_id':org,'title':'Explicit consignment','mode':'consignment','starts_on':today,'ends_on':'2030-12-31','terms':'Manual sold report and review','brand_terms':'Agreed branding','data_scope':'Sold reports only','facilities':'None'},owner,201)['data']
        cc=api(base,'POST',f'organizations/{center}/contracts/{cc["id"]}/publish',{'operation_id':str(uuid.uuid4()),'version':1},owner)['data']
        api(base,'POST',f'organizations/{org}/contracts/{cc["id"]}/accept',{'operation_id':str(uuid.uuid4()),'version':2,'consent':True,'consent_name':'Partner director'},lead_token)
        cr=api(base,'POST',f'orders/{order_id}/billing/reports',{'operation_id':str(uuid.uuid4()),'contract_id':cc['id'],'period_start':today,'period_end':today,'note':'Four actual pieces sold under consignment','lines':[{'order_line_id':original['lines'][0]['id'],'quantity_pieces':4}]},lead_token,201)['data']
        api(base,'POST',f'orders/{order_id}/billing/reports/{cr["id"]}/accept',{'operation_id':str(uuid.uuid4()),'version':1,'consent':True,'reason':'Actual sold report checked'},owner)
        ci=api(base,'POST',f'orders/{order_id}/billing/invoices',{'operation_id':str(uuid.uuid4()),'mode':'consignment','basis':'consignment_report','report_id':cr['id'],'due_on':'2030-12-31','discount_amount':0,'shipping_amount':0,'tax_basis_points':0,'rounding':'half_up','note':'Explicit report-only settlement'},owner,201)['data']
        assert ci['subtotal']==9000
        api(base,'POST',f'orders/{order_id}/billing/invoices/{ci["id"]}/accept',{'operation_id':str(uuid.uuid4()),'version':1,'consent':True,'reason':'Report invoice accepted'},lead_token)
        voucher=api(base,'POST',f'organizations/{org}/vouchers',{'operation_id':str(uuid.uuid4()),'code':'SMOKE1001','funding_organization_id':org,'kind':'fixed','value':1001,'maximum_discount':1001,'minimum_subtotal':0,'usage_limit':1,'starts_on':today,'ends_on':today,'store_ids':[store],'product_ids':[local_product_id],'restore_policy':'on_full_refund','terms':'Issuer funds; quota restored once on full refund'},lead_token,201)['data']
        assert 'SMOKE1001' in opener.open(web+f'/app/organizations/{org}/vouchers').read().decode()
        digital_shift=api(base,'POST',f'stores/{store}/shifts',{'operation_id':str(uuid.uuid4()),'device_name':'Manual noncash','opening_cash':0},cashier_token,201)['data']
        di=api(base,'POST',f'stores/{store}/sales/intents',{'operation_id':str(uuid.uuid4()),'shift_id':digital_shift['id'],'voucher_code':'SMOKE1001','lines':[{'product_id':local_product_id,'unit':'piece','quantity':2,'allocations':[{'batch_id':old_batch['id'],'bucket':'opened','quantity':2}]}]},cashier_token,201)['data']
        assert di['total_amount']==4999
        dp=f'/app/pos/intents/{di["id"]}/pay';dh=cashier_browser.open(web+f'/app/pos/intents/{di["id"]}').read().decode();payment=fields(dh,dp);payment.update({'payment_method':'qris_manual','payment_reference':'QRIS-MANUAL-1','verified':'1','verified_amount':'4999'})
        dr=cashier_browser.open(Request(web+dp,data=urlencode(payment).encode()));dr.read();digital_sale=dr.url.rsplit('/',1)[1]
        ds=api(base,'GET',f'shifts/{digital_shift["id"]}',token=lead_token)['data'];assert ds['expected_cash']==0 and ds['noncash_sales']==4999
        rp=f'/app/pos/sales/{digital_sale}/refunds';rh=opener.open(web+rp).read().decode();rf=fields(rh,rp+'/preview');rf.update({'shift':digital_shift['id']+':'+str(ds['version']),'stock_disposition':'quarantine','reference':'REFUND-QRIS-1','reason':'Actual returned goods and original QRIS refund verified','lines[0][quantity]':'2'})
        rh=web_post(rp+'/preview',rf).read().decode();confirm=fields(rh,rp+'/confirm');confirm['verified']='1';web_post(rp+'/confirm',confirm).read()
        refunded=api(base,'GET',f'sales/{digital_sale}',token=lead_token)['data'];assert refunded['refunds'][0]['amount']==4999 and refunded['payment_method']=='qris_manual'
        ds=api(base,'GET',f'shifts/{digital_shift["id"]}',token=cashier_token)['data'];assert ds['expected_cash']==0 and ds['noncash_refunds']==4999
        assert api(base,'GET',f'organizations/{org}/vouchers',token=lead_token)['data'][0]['used_count']==0
        api(base,'POST',f'shifts/{digital_shift["id"]}/close',{'operation_id':str(uuid.uuid4()),'version':ds['version'],'counted_cash':0},cashier_token)
        ledger_before+=5
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before
        # Native password change uses the existing authenticated endpoint: revoke every token.
        other_cashier_token=api(base,'POST','auth/login',{'email':cashier['email'],'password':PASSWORD,'device_name':'second-session'})['token']
        new_password=secrets.token_urlsafe(24)+'Aa7'
        api(base,'POST','auth/password',{'current_password':PASSWORD,'password':new_password,'password_confirmation':new_password},cashier_token,204)
        for revoked in [cashier_token,other_cashier_token]:
            api(base,'GET','auth/me',token=revoked,expected=401)
        api(base,'POST','auth/login',{'email':cashier['email'],'password':PASSWORD,'device_name':'old-denied'},expected=422)
        changed_session=api(base,'POST','auth/login',{'email':cashier['email'],'password':new_password,'device_name':'new-session'})['token']
        assert api(base,'GET','auth/me',token=changed_session)['data']['email']==cashier['email']
        assert api(base,'GET',f'stores/{store}/inventory/ledger',token=manager_token)['total']==ledger_before
        api(base,'POST','auth/logout',{},changed_session,204)
        api(base, 'POST', 'auth/logout', {}, lead_token, 204)
        api(base, 'GET', 'auth/me', token=lead_token, expected=401)
        print('PASS: live login/onboarding/catalog, web CSRF, proposal/approval, source dispatch, actual receipt 144 usable + 16 quarantine, operation replay, stock opening/cooking/loss, independent count review, stale snapshot denial, tenant isolation, cash POS/draft recovery, reprint, expense and shift closing, versioned minimums and live low-stock warnings, scoped native stock-control context, scoped audit CSV/onboarding isolation, stock ledger CSV/scoped signed pieces, cash reports and private CSV, native recipient capabilities, guarded order preview/create, versioned amendment preview/save, postapproval quantity proposal/hold/reject/accept, versioned account reactivation/replay and old-token denial, receipt discrepancy case and supplier-plan/partner-confirmation and physical replacement dispatch/actual receipt with once-only stock posting, revision preview/substitution after shipping, private product image and effective price, recipe component checkout, published contract/explicit consent, physical return quarantine, explicit invoice/tax/partial collection/confirmed credit, contract-backed sold-report consignment, scoped retail voucher/manual QRIS payment/reviewed original-method refund and restored quota, password rotation/revoked sessions, logout')
finally:
    for name in reversed(created):
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(['docker', 'network', 'rm', NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
