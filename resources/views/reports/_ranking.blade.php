@if (empty($rows))
    <x-empty icon="chart">Sem recebimentos no período.</x-empty>
@else
    <table class="table">
        <tbody>
        @foreach ($rows as $r)
            <tr>
                <td class="faint strong" style="width: 28px">{{ $loop->iteration }}</td>
                <td><a class="title" href="{{ route($route, $r['id']) }}">{{ $r['label'] }}</a></td>
                <td class="right strong nowrap">{{ \App\Support\Format::money($r['value']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
