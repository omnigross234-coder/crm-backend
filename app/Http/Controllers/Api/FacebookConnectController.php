<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FacebookConnectController extends Controller
{
    // Called after your app completes FB OAuth and gets a page access token
    public function connect(Request $request)
    {
        $user = Auth::user();
        if (!$user->isClientAdmin() && !$user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Only Client Admin can connect a page'], 403);
        }

        $data = $request->validate([
            'page_id'            => 'required|string',
            'page_name'          => 'required|string',
            'page_access_token'  => 'required|string',
        ]);

        $page = FacebookPage::updateOrCreate(
            ['page_id' => $data['page_id']],
            [
                'client_id'          => $user->client_id,
                'page_name'          => $data['page_name'],
                'page_access_token'  => $data['page_access_token'],
                'connected_by'       => $user->id,
            ]
        );

        ActivityLogger::log('facebook_page.connected', $page, ['page_name' => $page->page_name]);

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => FacebookPage::all()]);
    }
}