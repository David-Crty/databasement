<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The page size a listing endpoint asked for, kept within 1 to 100.
     */
    protected function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 15), 100));
    }
}
