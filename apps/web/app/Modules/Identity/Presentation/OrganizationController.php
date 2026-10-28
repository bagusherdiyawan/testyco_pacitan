<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;

final class OrganizationController
{
    public function __construct(private Backend $api) {}

    public function index(): mixed
    {
        return view('pages.organizations', ['organizations' => $this->api->page('organizations', 'organizations_page'), 'stores' => $this->api->page('stores', 'stores_page')]);
    }

    public function create(Request $r): mixed
    {
        $this->api->request('POST', 'organizations', $r->only('name', 'contact', 'address'));

        return back()->with('status', 'Mitra dibuat. Tambahkan toko dan pimpinan mitra.');
    }

    public function show(string $organization): mixed
    {
        return view('pages.organization', ['organization' => $organization, 'organizationInfo' => $this->api->request('GET', "organizations/$organization")->json('data'), 'stores' => $this->api->page("organizations/$organization/stores", 'stores_page'), 'users' => $this->api->page("organizations/$organization/users", 'users_page'), 'audit' => $this->api->page("organizations/$organization/audit", 'audit_page')]);
    }

    public function createStore(Request $r, string $organization): mixed
    {
        $this->api->request('POST', "organizations/$organization/stores", $r->only('name', 'region', 'address'));

        return back()->with('status', 'Toko ditambahkan.');
    }

    public function createUser(Request $r, string $organization): mixed
    {
        $data = $r->only('name', 'email', 'password', 'role');
        $data['store_ids'] = $r->input('store_ids', []);
        $this->api->request('POST', "organizations/$organization/users", $data);

        return back()->with('status', 'Pengguna ditambahkan.');
    }

    public function membership(Request $r, string $organization, string $user): mixed
    {
        $this->api->request('PATCH', "organizations/$organization/users/$user/membership", ['role' => $r->input('role'), 'store_ids' => $r->input('store_ids', [])]);

        return back()->with('status', 'Penugasan diperbarui; pengguna perlu masuk kembali.');
    }

    public function deactivate(string $organization, string $user): mixed
    {
        $this->api->request('POST', "organizations/$organization/users/$user/deactivate");

        return back()->with('status', 'Akun dinonaktifkan.');
    }

    public function storeStatus(Request $r, string $store): mixed
    {
        $this->api->request('PATCH', "stores/$store", ['active' => $r->boolean('active')]);

        return back()->with('status', 'Status toko diperbarui.');
    }

    public function organizationStatus(Request $r, string $organization): mixed
    {
        $this->api->request('PATCH', "organizations/$organization", ['active' => $r->boolean('active')]);

        return back()->with('status', 'Status organisasi diperbarui.');
    }
}
