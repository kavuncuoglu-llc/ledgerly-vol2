<?php

namespace App\Http\Controllers;

use App\Models\UserMemory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.memories', [
            'memories' => UserMemory::query()->where('user_id', $request->user()->id)->orderBy('key')->get(),
        ]);
    }

    public function destroy(Request $request, UserMemory $memory): RedirectResponse
    {
        abort_unless($memory->user_id === $request->user()->id, 403);

        $memory->delete();

        return redirect()->route('settings.memories.index');
    }
}
