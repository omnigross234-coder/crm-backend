<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    public function index()
    {
        $clients = Client::withCount('users', 'leads')
            ->with(['users' => fn ($query) => $query
                ->where('role', 'client_admin')
                ->select('id', 'client_id', 'name', 'email', 'role')])
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $clients]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            // The first Client Admin account, created together with the client
            'admin_name'     => 'required|string|max:255',
            'admin_email'    => 'required|email|unique:users,email',
            'admin_password' => 'required|min:8',
        ]);

        $client = DB::transaction(function () use ($data) {
            $client = Client::create([
                'name'   => $data['name'],
                'slug'   => Str::slug($data['name']) . '-' . Str::random(4),
                'status' => 'active',
            ]);

            User::create([
                'client_id' => $client->id,
                'name'      => $data['admin_name'],
                'email'     => $data['admin_email'],
                'password'  => Hash::make($data['admin_password']),
                'role'      => 'client_admin',
                'status'    => 'active',
            ]);

            return $client;
        });

        ActivityLogger::log('client.created', $client, ['name' => $client->name]);

        return response()->json(['success' => true, 'data' => $client->load('users')], 201);
    }

    public function show(Client $client)
    {
        return response()->json(['success' => true, 'data' => $client->load('users')]);
    }

    public function update(Request $request, Client $client)
    {
        $data = $request->validate([
            'name'   => 'sometimes|required|string|max:255',
            'status' => 'sometimes|required|in:active,suspended',
            'admin_email' => 'sometimes|required|email|unique:users,email,'
                . optional($client->users()->where('role', 'client_admin')->first())->id,
            'admin_password' => 'sometimes|nullable|string|min:8',
        ]);

        DB::transaction(function () use ($client, $data) {
            $clientData = [];
            if (array_key_exists('name', $data)) {
                $clientData['name'] = $data['name'];
                $clientData['slug'] = Str::slug($data['name']) . '-' . Str::random(4);
            }
            if (array_key_exists('status', $data)) {
                $clientData['status'] = $data['status'];
            }
            $client->update($clientData);
            
            foreach ($client->users as $user) {

                $user->tokens()->delete();

            }
            // A legacy client may not have a client-admin user.  Client
            // fields must remain editable in that case, so only query and
            // update an admin when admin-specific data was supplied.
            if (array_key_exists('admin_email', $data) || ! empty($data['admin_password'])) {
                $admin = $client->users()->where('role', 'client_admin')->first();

                if ($admin) {
                    if (array_key_exists('admin_email', $data)) {
                        $admin->email = $data['admin_email'];
                    }
                    if (! empty($data['admin_password'])) {
                        $admin->password = Hash::make($data['admin_password']);
                    }
                    $admin->save();
                }
            }
        });
        ActivityLogger::log('client.updated', $client, $data);

        return response()->json([
            'success' => true,
            'data' => $client->fresh()->load('users'),
        ]);
    }

    public function destroy(Client $client)
    {
        ActivityLogger::log('client.deleted', $client, ['name' => $client->name]);
        $client->delete();

        return response()->json(['success' => true, 'message' => 'Client removed']);
    }
}
