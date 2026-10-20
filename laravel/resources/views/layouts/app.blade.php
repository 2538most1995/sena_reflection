<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#12324f">
    <title>@yield('title', 'SENA Reflection')</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
    <link rel="stylesheet" href="{{ asset('edit.css') }}">
    <link rel="stylesheet" href="{{ asset('admin.css') }}">
</head>
<body class="@yield('body-class')">@yield('body')</body>
</html>
