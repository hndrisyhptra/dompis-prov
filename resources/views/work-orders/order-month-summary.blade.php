<section class="panel gap-top" aria-labelledby="order-month-title">
    <div class="panel-head"><div><h2 id="order-month-title">Penyelesaian berdasarkan bulan order</h2><p class="small muted">Kelompok berdasarkan TGL ORDER · Status penyelesaian terkini sesuai filter aktif</p></div></div>
    <div class="table-wrap" tabindex="0" aria-label="Rekap penyelesaian per bulan order, geser untuk melihat seluruh kolom"><table class="area-type-table order-month-table"><thead><tr><th scope="col">Bulan order</th><th scope="col">Total WO</th><th scope="col">Progress CLOSE</th><th scope="col">Sudah PS</th><th scope="col">Outstanding</th></tr></thead><tbody>
    @forelse($orderMonths['rows'] as $row)<tr><th scope="row">{{ $row['label'] }}</th>
        @foreach(['total'=>'all', 'closed'=>'closed', 'ps'=>'complete', 'outstanding'=>'outstanding'] as $metric=>$queue)
        <td class="numeric"><x-order-count :count="$row[$metric]" :filters="array_replace($monthScopeFilters, ['order_month'=>$row['month'], 'queue'=>$queue])" :label="$row['label'].' · '.['total'=>'Total WO', 'closed'=>'Progress CLOSE', 'ps'=>'Sudah PS', 'outstanding'=>'Outstanding'][$metric]"/></td>
        @endforeach
    </tr>@empty<tr><td colspan="5"><div class="empty"><h3>Belum ada order bertanggal</h3><p>Rekap terisi setelah order dengan TGL ORDER tersedia pada cakupan ini.</p></div></td></tr>@endforelse
    </tbody></table></div>
    <div class="area-table-note small muted">Sudah PS adalah bagian dari Progress CLOSE dan wajib memenuhi 14 Status Bima. Status Bima tersebut otomatis menyesuaikan progress menjadi CLOSE saat import. Bulan di sini adalah bulan masuk order, bukan bulan terjadinya PS; grafik harian tetap memakai TGL PS. Waktu penyesuaian otomatis tidak dianggap tanggal PS. @if($orderMonths['undated']){{ number_format($orderMonths['undated']) }} order tanpa TGL ORDER tidak masuk rekap bulan.@endif</div>
</section>
