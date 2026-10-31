<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\LegalCase;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->query('q'));

        $contacts = $cases = $tasks = collect();
        if (mb_strlen($term) >= 2) {
            $contacts = Contact::search($term)->orderBy('name')->limit(10)->get();
            $cases = LegalCase::with('client')->search($term)->latest()->limit(10)->get();
            $tasks = Task::with('legalCase')->search($term)->latest()->limit(10)->get();
        }

        return view('search', compact('term', 'contacts', 'cases', 'tasks'));
    }
}
