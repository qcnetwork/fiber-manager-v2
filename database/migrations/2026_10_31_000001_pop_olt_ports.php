<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Areas become POPs (their center is the POP location), OLTs get PON ports grouped by slot,
 * and ODCs connect to an OLT port instead of storing an OLT and a bare port number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('address')->nullable()->after('center_lng');
            $table->text('notes')->nullable()->after('address');
        });

        Schema::table('olts', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('brand');
        });

        Schema::create('olt_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');
            $table->string('status', 20)->default('normal');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['olt_id', 'slot', 'port']);
        });

        // Several ODCs may share one PON port (splitter at the POP), so no unique index.
        Schema::table('odcs', function (Blueprint $table) {
            $table->foreignId('olt_port_id')->nullable()->after('area_id')->constrained()->nullOnDelete();
        });

        $this->convertExistingData();

        Schema::table('odcs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('olt_id');
            $table->dropColumn('olt_pon_port');
        });

        // An OLT always sits at its POP.
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }

    /**
     * Keeps what is known: OLT IPs written in the notes, ODC uplinks as slot 1 ports (the old data has no slot;
     * `migrasi:fiber-manager-olt` restores the real cards), and feeder cables starting at an OLT now start at its POP.
     */
    private function convertExistingData(): void
    {
        foreach (DB::table('olts')->get() as $olt) {
            if ($olt->notes && preg_match('/^IP:\s*(\S+)\s*$/mi', $olt->notes, $m)) {
                $notes = trim(preg_replace('/^IP:\s*\S+\s*$/mi', '', $olt->notes));
                DB::table('olts')->where('id', $olt->id)->update(['ip_address' => mb_substr($m[1], 0, 45), 'notes' => $notes ?: null]);
            }

            $odcs = DB::table('odcs')->where('olt_id', $olt->id)->get(['id', 'olt_pon_port']);
            $count = max((int) $olt->pon_ports, (int) $odcs->max('olt_pon_port'), 1);
            $now = now();
            DB::table('olt_ports')->insert(array_map(
                fn ($port) => ['olt_id' => $olt->id, 'slot' => 1, 'port' => $port, 'status' => 'normal', 'created_at' => $now, 'updated_at' => $now],
                range(1, $count),
            ));

            $ports = DB::table('olt_ports')->where('olt_id', $olt->id)->pluck('id', 'port');
            foreach ($odcs as $odc) {
                if ($odc->olt_pon_port && isset($ports[$odc->olt_pon_port])) {
                    DB::table('odcs')->where('id', $odc->id)->update(['olt_port_id' => $ports[$odc->olt_pon_port]]);
                }
            }

            foreach (['from', 'to'] as $end) {
                DB::table('cables')->where("{$end}_type", 'olt')->where("{$end}_id", $olt->id)
                    ->update(["{$end}_type" => 'pop', "{$end}_id" => $olt->area_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
        });

        Schema::table('odcs', function (Blueprint $table) {
            $table->foreignId('olt_id')->nullable()->after('area_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('olt_pon_port')->nullable()->after('olt_id');
        });

        foreach (DB::table('odcs')->whereNotNull('olt_port_id')->get(['id', 'olt_port_id']) as $odc) {
            $port = DB::table('olt_ports')->find($odc->olt_port_id);
            DB::table('odcs')->where('id', $odc->id)->update(['olt_id' => $port->olt_id, 'olt_pon_port' => $port->port]);
        }

        Schema::table('odcs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('olt_port_id');
        });
        Schema::dropIfExists('olt_ports');

        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn(['address', 'notes']);
        });
    }
};
