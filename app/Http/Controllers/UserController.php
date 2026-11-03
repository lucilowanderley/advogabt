<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::orderByDesc('active')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User(['role' => 'advogado', 'active' => true, 'color' => '#c9a24d'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        User::create($this->validated($request));
        Cache::forget('security-default-passwords');

        return redirect()->route('users.index')->with('success', 'Usuário criado.');
    }

    public function edit(User $user): View
    {
        return view('users.form', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        if ($user->is($request->user())) {
            // Evita que o administrador remova o próprio acesso.
            $data['role'] = 'admin';
            $data['active'] = true;
        }
        $user->update($data);
        Cache::forget('security-default-passwords');

        return redirect()->route('users.index')->with('success', 'Usuário atualizado.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Você não pode excluir o seu próprio usuário.');
        }
        $user->delete();
        Cache::forget('security-default-passwords');

        return redirect()->route('users.index')->with('success', 'Usuário excluído.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(Options::USER_ROLES))],
            'oab' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'active' => ['nullable', 'boolean'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ]);
        $data['active'] = $request->boolean('active');
        $data['color'] = $data['color'] ?? '#c9a24d';
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }
}
