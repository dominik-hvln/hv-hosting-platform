<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostingAccount extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'purchased_hosting_id',
        'username',
        'domain',
        'server_id',
        'status',
        'current_ram',
        'current_cpu',
        'current_storage',
        'current_bandwidth',
        'cloudlinux_id',
        'directadmin_username',
        'is_autoscaling_enabled',
        'auto_backup_enabled',
        'last_login_at',
        'is_suspended',
        'suspension_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'current_ram' => 'integer',
        'current_cpu' => 'integer',
        'current_storage' => 'integer',
        'current_bandwidth' => 'integer',
        'is_autoscaling_enabled' => 'boolean',
        'auto_backup_enabled' => 'boolean',
        'last_login_at' => 'datetime',
        'is_suspended' => 'boolean',
    ];

    /**
     * Get the user that owns the hosting account.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the purchased hosting associated with this account.
     */
    public function purchasedHosting()
    {
        return $this->belongsTo(PurchasedHosting::class);
    }

    /**
     * Get the scaling logs for this account.
     */
    public function scalingLogs()
    {
        return $this->hasMany(ScalingLog::class);
    }

    /**
     * Check if autoscaling is enabled.
     */
    public function isAutoscalingEnabled(): bool
    {
        return $this->is_autoscaling_enabled && $this->purchasedHosting->is_autoscaling_enabled;
    }

    /**
     * Enable autoscaling.
     */
    public function enableAutoscaling(): bool
    {
        $this->is_autoscaling_enabled = true;
        return $this->save();
    }

    /**
     * Disable autoscaling.
     */
    public function disableAutoscaling(): bool
    {
        $this->is_autoscaling_enabled = false;
        return $this->save();
    }

    /**
     * Get resource usage from CloudLinux.
     */
    public function getResourceUsage()
    {
        if (!$this->cloudlinux_id) {
            return null;
        }

        // Integration with CloudLinux service
        return app(CloudLinuxService::class)->getResourceUsage($this->cloudlinux_id);
    }

    /**
     * Scale resources.
     */
    public function scaleResources(int $ram = 0, int $cpu = 0): ScalingLog
    {
        $oldRam = $this->current_ram;
        $oldCpu = $this->current_cpu;

        // Increase resources if specified
        if ($ram > 0) {
            $this->current_ram += $ram;
        }

        if ($cpu > 0) {
            $this->current_cpu += $cpu;
        }

        // Apply changes to CloudLinux
        if ($ram > 0 || $cpu > 0) {
            app(CloudLinuxService::class)->updateResources(
                $this->cloudlinux_id,
                $this->current_ram,
                $this->current_cpu
            );
        }

        $this->save();

        // Create scaling log
        return $this->scalingLogs()->create([
            'purchased_hosting_id' => $this->purchased_hosting_id,
            'previous_ram' => $oldRam,
            'previous_cpu' => $oldCpu,
            'new_ram' => $this->current_ram,
            'new_cpu' => $this->current_cpu,
            'scaled_ram' => $ram,
            'scaled_cpu' => $cpu,
            'reason' => 'autoscaling',
        ]);
    }

    /**
     * Check if resources need scaling based on current usage.
     */
    public function needsScaling(): array
    {
        if (!$this->isAutoscalingEnabled()) {
            return ['needs_scaling' => false];
        }

        $usage = $this->getResourceUsage();

        if (!$usage) {
            return ['needs_scaling' => false];
        }

        $hostingPlan = $this->purchasedHosting->hostingPlan;
        $scaleRam = 0;
        $scaleCpu = 0;
        $needsScaling = false;

        // Check RAM scaling conditions
        $ramUsagePercent = ($usage['ram_usage'] / $this->current_ram) * 100;
        if ($ramUsagePercent >= config('autoscaling.ram_threshold', 80) && $this->current_ram < $hostingPlan->max_ram) {
            $scaleRam = config('autoscaling.ram_step', 256);
            $needsScaling = true;
        }

        // Check CPU scaling conditions
        $cpuUsagePercent = ($usage['cpu_usage'] / $this->current_cpu) * 100;
        if ($cpuUsagePercent >= config('autoscaling.cpu_threshold', 50) && $this->current_cpu < $hostingPlan->max_cpu) {
            $scaleCpu = config('autoscaling.cpu_step', 50);
            $needsScaling = true;
        }

        return [
            'needs_scaling' => $needsScaling,
            'scale_ram' => $scaleRam,
            'scale_cpu' => $scaleCpu,
            'ram_usage_percent' => $ramUsagePercent,
            'cpu_usage_percent' => $cpuUsagePercent,
        ];
    }

    /**
     * Suspend account.
     */
    public function suspend(string $reason = 'Payment overdue'): bool
    {
        $this->is_suspended = true;
        $this->suspension_reason = $reason;
        $this->status = 'suspended';

        // Integration with DirectAdmin
        app(DirectAdminService::class)->suspendAccount($this->directadmin_username);

        return $this->save();
    }

    /**
     * Unsuspend account.
     */
    public function unsuspend(): bool
    {
        $this->is_suspended = false;
        $this->suspension_reason = null;
        $this->status = 'active';

        // Integration with DirectAdmin
        app(DirectAdminService::class)->unsuspendAccount($this->directadmin_username);

        return $this->save();
    }

    /**
     * Scope for active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('is_suspended', false);
    }

    /**
     * Scope for suspended accounts.
     */
    public function scopeSuspended($query)
    {
        return $query->where('is_suspended', true);
    }

    /**
     * Scope for accounts with autoscaling enabled.
     */
    public function scopeAutoscalingEnabled($query)
    {
        return $query->where('is_autoscaling_enabled', true);
    }
}