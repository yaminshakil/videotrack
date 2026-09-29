{{-- Shared by the admin and manager account pages. Expects $user, $action, $heading, $tagline, $roleLabel. --}}
@push('styles')
<style>
  .aform{max-width:640px}
  .aform .block{padding:20px}
  .aform .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .aform .full{grid-column:1/-1}
  .aform label{display:block;font-size:13px;color:var(--muted);margin:0 0 4px}
  .aform input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);
        background:var(--panel2);color:var(--text);font-size:15px}
  .aform .hint{font-size:13px;color:var(--muted);margin:5px 0 0}
  .aform hr{border:0;border-top:1px solid var(--border);margin:20px 0 16px}
  .aform h2{font-size:18px;margin:0 0 4px}
  .aform h2+.hint{margin:0 0 12px}
  button{padding:10px 16px;border-radius:12px;border:0;cursor:pointer;font-weight:700;font-size:15px;color:#fff;
        background:linear-gradient(135deg,var(--accent),var(--accent2));width:auto}
  .whoami{font-size:15px;color:var(--muted);margin:0 0 16px}
  .whoami b{color:var(--text)}
  @media(max-width:560px){.aform .grid{grid-template-columns:1fr}}
</style>
@endpush

<form class="block aform" method="post" action="{{ $action }}">
  @csrf
  @method('PUT')

  <h2>{{ $heading }}</h2>
  <p class="hint">{{ $tagline }}</p>

  <p class="whoami">Signed in as <b>{{ $user->username }}</b> ({{ $roleLabel }}).</p>

  <div class="grid">
    <div class="full">
      <label for="acc-name">Full name</label>
      <input id="acc-name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="120" autocomplete="name">
    </div>

    <div class="full">
      <label for="acc-username">Username</label>
      <input id="acc-username" type="text" name="username" value="{{ old('username', $user->username) }}" required
             minlength="3" maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false">
      <p class="hint">This is what you type on the sign-in page. It has to be different from every employee and manager username.</p>
    </div>

    <div class="full">
      <label for="acc-current">Current password</label>
      <input id="acc-current" type="password" name="current_password" required autocomplete="current-password">
      <p class="hint">Required, so nobody can take the account over from an open or shared browser.</p>
    </div>

    <div class="full">
      <hr>
      <h2 style="font-size:16px">Change password</h2>
      <p class="hint">Leave both boxes empty to keep your current password.</p>
    </div>

    <div>
      <label for="acc-password">New password</label>
      <input id="acc-password" type="password" name="password" minlength="8" autocomplete="new-password">
    </div>

    <div>
      <label for="acc-password-confirm">Repeat new password</label>
      <input id="acc-password-confirm" type="password" name="password_confirmation" minlength="8" autocomplete="new-password">
    </div>

    <div class="full">
      <button type="submit">Save changes</button>
    </div>
  </div>
</form>
