<dialog id="dashboard-order-dialog" class="dashboard-order-dialog" aria-labelledby="dashboard-order-title">
    <div class="panel-head"><div><h2 id="dashboard-order-title">Daftar Work Order</h2><p class="small muted">Data terkini sesuai filter dan cakupan akses Anda.</p></div><button type="button" class="button secondary icon-button" data-order-dialog-close aria-label="Tutup daftar order" title="Tutup"><span aria-hidden="true">×</span></button></div>
    <div data-order-dialog-content aria-live="polite" aria-busy="false"></div>
    <div class="dashboard-dialog-footer"><a class="button secondary" href="{{ route('work-orders.index') }}" data-order-full-list>Buka di Work Order</a><button class="button primary" type="button" data-order-dialog-close>Tutup</button></div>
</dialog>
