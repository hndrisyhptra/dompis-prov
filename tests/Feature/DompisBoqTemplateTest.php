<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DesignatorPackagePrice;
use App\Models\Package;
use App\Models\QeBoq;
use App\Models\WorkOrder;
use App\Services\Boq\BoqImportService;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ZipArchive;

class DompisBoqTemplateTest extends OloTestCase
{
    private function template(int $package, array $volumes = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'olo-boq-template-');
        copy(resource_path('templates/boq/paket-'.$package.'.xlsx'), $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $doc = new DOMDocument;
        $doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($volumes as $row => $value) {
            $parent = $xp->query('//s:row[@r="'.$row.'"]')->item(0);
            $old = $xp->query('s:c[@r="G'.$row.'"]', $parent)->item(0);
            if ($old) {
                $parent->removeChild($old);
            }
            $cell = $doc->createElementNS($parent->namespaceURI, 'c');
            $cell->setAttribute('r', 'G'.$row);
            $cell->setAttribute('t', 'n');
            if (is_array($value)) {
                $cell->appendChild($doc->createElementNS($parent->namespaceURI, 'f', $value['formula']));
                $value = $value['value'];
            }
            $cell->appendChild($doc->createElementNS($parent->namespaceURI, 'v', (string) $value));
            $parent->appendChild($cell);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $doc->saveXML());
        $zip->close();

        return new UploadedFile($path, 'boq.xlsx', null, null, true);
    }

    public function test_both_actual_templates_read_column_g_ignore_blank_zero_rows_and_preserve_price_formula_columns(): void
    {
        foreach ([5 => 2, 10 => 3] as $number => $id) {
            $file = $this->template($number, [9 => '1.250', 10 => '2', 642 => '0']);
            try {
                $parsed = app(BoqImportService::class)->parse($file, Package::findOrFail($id));
                $this->assertCount(2, $parsed['items']);
                $items = collect($parsed['items'])->keyBy('designator_code');
                $this->assertSame('1.250', $items['M-AB-OF-SM-12D']['qty']);
                $this->assertSame('2.000', $items['J-AB-OF-SM-12D']['qty']);
                $this->assertSame($number === 5 ? '7098.00' : '7931.00', $items['M-AB-OF-SM-12D']['unit_price']);
                $this->assertSame($number === 5 ? '13210.50' : '14567.75', $parsed['grand_total']);
            } finally {
                unlink($file->getRealPath());
            }
        }
    }

    public function test_blank_template_wrong_package_negative_and_formula_volumes_are_rejected(): void
    {
        foreach ([[[], 2, InvalidArgumentException::class], [[9 => 1], 3, InvalidArgumentException::class], [[9 => '-1'], 2, ValidationException::class], [[9 => ['formula' => 'SUM(1,1)', 'value' => 2]], 2, ValidationException::class], [[9 => '1.1234'], 2, ValidationException::class]] as [$rows, $id, $error]) {
            $file = $this->template(5, $rows);
            try {
                app(BoqImportService::class)->parse($file, Package::findOrFail($id));
                $this->fail('Invalid volume/template must be rejected');
            } catch (InvalidArgumentException|ValidationException $e) {
                $this->assertInstanceOf($error, $e);
            } finally {
                unlink($file->getRealPath());
            }
        }
    }

    public function test_all_724_template_items_import_for_each_package_using_master_prices(): void
    {
        foreach ([5 => 2, 10 => 3] as $number => $id) {
            $file = $this->template($number, array_fill_keys(range(9, 732), 1));
            try {
                $parsed = app(BoqImportService::class)->parse($file, Package::findOrFail($id));
                $this->assertCount(724, $parsed['items']);
                // Three Paket 10 template cells have Rp1; the existing master is Rp0 and remains authoritative.
                $this->assertSame($number === 5 ? '617477633.00' : '674405112.00', $parsed['grand_total']);
            } finally {
                unlink($file->getRealPath());
            }
        }
    }

    public function test_all_template_designators_have_master_prices_but_existing_prices_are_never_replaced(): void
    {
        $this->assertDatabaseCount('designator_package_prices', 1448);
        foreach ([2, 3] as $id) {
            $this->assertSame(724, DesignatorPackagePrice::where('package_id', $id)->count());
        }
        DesignatorPackagePrice::where('package_id', 2)->where('designator_id', 531)->update(['price' => '123.45']);
        $migration = require database_path('migrations/2026_10_25_000018_complete_package5_template_prices.php');
        $migration->up();
        $this->assertSame('123.45', DesignatorPackagePrice::where('package_id', 2)->where('designator_id', 531)->sole()->price);
    }

    public function test_upload_template_downloads_and_idempotence_preserve_order_progress(): void
    {
        $this->pull([$this->row('TEMPLATE-WO')]);
        $order = WorkOrder::sole();
        $order->update(['progress_category' => 'JT']);
        $actor = $this->user(Role::BranchHd);
        $actor->branches()->attach($this->branch);
        $this->actingAs($actor);
        Storage::fake('local');
        foreach ([5, 10] as $number) {
            $response = $this->get(route('boqs.template', [$order, 'package' => $number]))->assertOk();
            $this->assertSame(hash_file('sha256', resource_path('templates/boq/paket-'.$number.'.xlsx')), hash_file('sha256', $response->baseResponse->getFile()->getPathname()));
        }
        $before = $order->fresh()->getRawOriginal();
        for ($i = 0; $i < 2; $i++) {
            $file = $this->template(5, [9 => '1.25', 10 => 2]);
            try {
                $this->post(route('boqs.store', $order), ['file' => $file, 'package_id' => 2, 'order_revision' => $order->revision, 'boq_revision' => $i, 'header_row' => 1])->assertSessionHasNoErrors()->assertRedirect(route('boqs.show', $order));
            } finally {
                unlink($file->getRealPath());
            }
        }
        $this->assertSame(1, QeBoq::sole()->revision);
        $this->assertDatabaseCount('qe_boq_histories', 1);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->get(route('boqs.show', $order))->assertOk()->assertSee('13.210,50')->assertSee('Peta Order / KML');
    }
}
