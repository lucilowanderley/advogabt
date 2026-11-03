<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{{ $subjectLine }}</title></head>
<body style="margin:0;padding:0;background:#f1f3f7;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f7;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">
    <tr><td style="background:#0f1a2e;padding:16px 24px;color:#d4af37;font-weight:700;font-size:16px;letter-spacing:.5px">{{ config('app.name') }}</td></tr>
    <tr><td style="padding:24px">
        <h1 style="margin:0 0 14px;font-size:19px;color:#0f1a2e">{{ $heading }}</h1>
        @foreach ($lines as $line)
            <p style="margin:0 0 12px;font-size:14px;line-height:1.55;white-space:pre-line">{{ $line }}</p>
        @endforeach
        @if ($items)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 14px;border-collapse:collapse">
                @foreach ($items as $item)
                    <tr><td style="padding:9px 0;border-top:1px solid #eef0f4">
                        <div style="font-size:14px;font-weight:600;color:{{ ! empty($item['late']) ? '#b91c1c' : '#111827' }}">
                            @if (! empty($item['url']))<a href="{{ $item['url'] }}" style="color:inherit;text-decoration:none">{{ $item['title'] }}</a>@else{{ $item['title'] }}@endif
                        </div>
                        @if (! empty($item['meta']))<div style="font-size:12px;color:#6b7280;margin-top:2px">{{ $item['meta'] }}</div>@endif
                    </td></tr>
                @endforeach
            </table>
        @endif
        @if ($buttonUrl)
            <p style="margin:18px 0 4px"><a href="{{ $buttonUrl }}" style="display:inline-block;background:#d4af37;color:#0f1a2e;font-weight:700;font-size:14px;padding:10px 18px;border-radius:6px;text-decoration:none">{{ $buttonLabel ?: 'Abrir no '.config('app.name') }}</a></p>
        @endif
    </td></tr>
    <tr><td style="padding:14px 24px;background:#f8fafc;font-size:11px;color:#9ca3af">E-mail automático enviado pelo sistema do escritório. @if ($ics)O arquivo anexo (.ics) adiciona o compromisso ao Google Agenda, Outlook ou iPhone.@endif</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
