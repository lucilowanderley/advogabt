<!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
    @include('layouts.head', ['title' => $template->name])
</head>
<body style="background: #e9ecf2">
    <div class="no-print row between" style="max-width: 820px; margin: 20px auto">
        <span class="muted">{{ $template->name }} — revise o texto e imprima ou salve em PDF.</span>
        <button class="btn btn-primary" onclick="window.print()"><x-icon name="printer" class="sm" /> Imprimir / PDF</button>
    </div>
    <div class="doc-paper" contenteditable="true" spellcheck="true">{{ $content }}</div>
    <div style="height: 40px" class="no-print"></div>
</body>
</html>
