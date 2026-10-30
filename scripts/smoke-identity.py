"""Isolated HTTP integration smoke: temporary DB, disposable API/web containers.
Does not seed or modify the normal development database. Requires Docker.
"""
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import tempfile
import time
import uuid
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
        common = ['--user', f'{os.getuid()}:{os.getgid()}', '--network', NAME, '-p', '127.0.0.1::8000', '-v', f'{ROOT}:/app', '-e', 'APP_ENV=testing', '-e', 'APP_DEBUG=false', '-e', 'NO_PROXY=api,localhost,127.0.0.1', '-e', 'no_proxy=api,localhost,127.0.0.1']
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
        assert not api(base,'GET',minimum_detail_path,token=cashier_token)['can_manage']
        opener = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        html = opener.open(web + '/login').read().decode()
        csrf = re.search(r'name="_token" value="([^"]+)"', html).group(1)
        opener.open(Request(web + '/login', data=urlencode({'_token':csrf,'email':cashier['email'],'password':PASSWORD}).encode())).read()
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
        pay_path = f'/app/pos/intents/{intent_id}/pay'; payment = fields(html, pay_path); payment['cash_received']='20000'
        response = web_post(pay_path, payment); html=response.read().decode(); sale_id=response.url.rsplit('/',1)[1]
        assert '12.000' in html and '8.000' in html and 'Cetak melalui browser' in html
        assert web_post(pay_path,payment).url.endswith(sale_id)
        sale = api(base,'GET',f'sales/{sale_id}',token=cashier_token)['data']
        assert sale['total_amount']==12000 and sale['change_amount']==8000
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
        import csv
        import io
        from datetime import datetime, timedelta, timezone
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
        opener=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
        html=opener.open(web+'/login').read().decode()
        csrf=re.search(r'name="_token" value="([^"]+)"',html).group(1)
        opener.open(Request(web+'/login',data=urlencode({'_token':csrf,'email':lead['email'],'password':PASSWORD}).encode())).read()
        web_period=urlencode({'from_day':sold_day.date().isoformat(),'to_day':sold_day.date().isoformat()})
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
        api(base, 'POST', 'auth/logout', {}, lead_token, 204)
        api(base, 'GET', 'auth/me', token=lead_token, expected=401)
        print('PASS: live login/onboarding/catalog, web CSRF, proposal/approval, source dispatch, actual receipt 144 usable + 16 quarantine, operation replay, stock opening/cooking/loss, independent count review, stale snapshot denial, tenant isolation, cash POS/draft recovery, reprint, expense and shift closing, versioned minimums and live low-stock warnings, scoped native stock-control context, cash reports and private CSV, native recipient capabilities, guarded order preview/create, versioned amendment preview/save, logout')
finally:
    for name in reversed(created):
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(['docker', 'network', 'rm', NAME], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
