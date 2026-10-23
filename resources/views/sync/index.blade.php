@extends('layouts.app')
@section('title', 'Sync Center')
@section('content')
@if(config('olo.inbound') === 'excel')
@include('sync.excel-inbound')
@else
@include('sync.sheets-inbound')
@endif
@include('sync.history')
@endsection
