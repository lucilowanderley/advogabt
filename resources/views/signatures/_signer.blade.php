@php use App\Models\SignatureSigner; @endphp
<div class="signer-row form-grid" data-signer-row>
    <div class="field col-4"><label>Nome *</label><input type="text" name="signers[{{ $i }}][name]" value="{{ $s['name'] ?? '' }}" maxlength="150" required></div>
    <div class="field col-4"><label>E-mail *</label><input type="email" name="signers[{{ $i }}][email]" value="{{ $s['email'] ?? '' }}" maxlength="255" required></div>
    <div class="field col-4"><label>Celular (SMS/WhatsApp)</label><input type="text" name="signers[{{ $i }}][phone]" value="{{ $s['phone'] ?? '' }}" maxlength="30" data-mask="phone" placeholder="(11) 99999-8888"></div>
    <div class="field col-4"><label>CPF</label><input type="text" name="signers[{{ $i }}][document]" value="{{ $s['document'] ?? '' }}" maxlength="20" data-mask="doc"></div>
    <div class="field col-3"><label>Assina como</label>
        <select name="signers[{{ $i }}][role]">@foreach (SignatureSigner::ROLES as $k => $l)<option value="{{ $k }}" @selected(($s['role'] ?? 'parte') === $k)>{{ $l }}</option>@endforeach</select>
    </div>
    <div class="field col-4"><label>Confirmação de identidade</label>
        <select name="signers[{{ $i }}][auth]">@foreach (SignatureSigner::AUTHS as $k => $l)<option value="{{ $k }}" @selected(($s['auth'] ?? 'email') === $k)>{{ $l }}</option>@endforeach</select>
    </div>
    <div class="field col-1"><label>&nbsp;</label><button type="button" class="btn btn-ghost" data-signer-remove title="Remover"><x-icon name="trash" class="sm" /></button></div>
    @foreach (['name', 'email', 'phone'] as $f)
        @error("signers.$i.$f")<div class="col-12 error">{{ $message }}</div>@enderror
    @endforeach
</div>
