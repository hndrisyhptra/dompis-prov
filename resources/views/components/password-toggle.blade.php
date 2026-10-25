@props(['target'])
<button type="button" class="password-toggle" data-password-toggle aria-controls="{{ $target }}" aria-pressed="false" aria-label="Tampilkan kata sandi" title="Tampilkan kata sandi" hidden><span data-password-show><x-icon name="eye"/></span><span data-password-hide hidden><x-icon name="eye-off"/></span></button>
