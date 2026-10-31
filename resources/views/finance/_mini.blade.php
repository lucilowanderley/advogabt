@php use App\Support\Format; @endphp
@if ($entries->isEmpty())
    <x-empty icon="wallet">Nenhum lançamento.</x-empty>
@else
    <div class="table-wrap">
        <table class="table">
            <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td>
                        <div class="strong">{{ $entry->description }}</div>
                        <div class="small faint">Venc. {{ $entry->due_date->format('d/m/Y') }}</div>
                    </td>
                    <td class="right nowrap {{ $entry->type === 'receita' ? 'text-green' : 'text-red' }}">{{ $entry->type === 'receita' ? '+' : '−' }} {{ Format::money($entry->amount) }}</td>
                    <td class="right">
                        @php $s = $entry->situation; @endphp
                        <span class="badge {{ $s === 'pago' ? 'green' : ($s === 'atrasado' ? 'red' : 'orange') }}">{{ $s === 'pago' ? ($entry->type === 'receita' ? 'Recebido' : 'Pago') : ($s === 'atrasado' ? 'Atrasado' : 'Em aberto') }}</span>
                    </td>
                    <td class="right"><a class="btn btn-xs btn-ghost" href="{{ route('finance.edit', $entry) }}"><x-icon name="edit" class="sm" /></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
