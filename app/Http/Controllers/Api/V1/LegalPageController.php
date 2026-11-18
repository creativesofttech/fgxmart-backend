<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\LegalPageStore;
use Illuminate\Http\Request;

class LegalPageController extends Controller
{
    public function show(string $page, LegalPageStore $store)
    {
        return response()->json(['data' => $store->read($page)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $page, LegalPageStore $store)
    {
        $data = $request->validate(['title' => 'required|string|max:255', 'content' => 'required|string|max:100000']);
        return response()->json(['data' => $store->save($page, $data), 'message' => 'Page saved successfully.'])->header('Cache-Control', 'no-store');
    }
}
