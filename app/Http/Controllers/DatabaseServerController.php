<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDatabaseServerRequest;
use App\Http\Requests\UpdateDatabaseServerRequest;
use App\Models\DatabaseServer;

class DatabaseServerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDatabaseServerRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(DatabaseServer $databaseServer)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(DatabaseServer $databaseServer)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDatabaseServerRequest $request, DatabaseServer $databaseServer)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DatabaseServer $databaseServer)
    {
        //
    }
}
