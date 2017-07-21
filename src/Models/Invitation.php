<?php

namespace ZiNETHQ\SparkInvite\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Spark\Spark;
use Carbon\Carbon;
use Webpatser\Uuid\Uuid as Uuid;

use ZiNETHQ\SparkInvite\SparkInvite;

use Auth;
use Event;
use Log;
use Password;

class Invitation extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ISSUED = 'issued';
    const STATUS_SUCCESSFUL = 'successful';
    const STATUS_REVOKED = 'revoked';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = [
        self::STATUS_REVOKED,
        self::STATUS_REJECTED
    ];
    const STATUS = [
        self::STATUS_PENDING,
        self::STATUS_ISSUED,
        self::STATUS_SUCCESSFUL,
        self::STATUS_REVOKED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED
    ];

    public $timestamps = true;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_invitations';
    protected $with = ['referrerTeam', 'referrer', 'invitee'];
    protected $appends = ['status'];
    protected $hidden = ['old_password'];
    protected $casts = [
        'data' => 'array',
    ];

    public static function make($referrerTeam, $referrer, $invitee, $data = null)
    {
        // Make the invitation
        $invitation = new Invitation();
        $invitation->referrerTeam()->associate($referrerTeam);
        $invitation->referrer()->associate($referrer);
        $invitation->invitee()->associate($invitee);
        $invitation->old_password = $invitee->password;
        $invitation->data = collect($data);
        $invitation->save();

        // Generate its token
        $invitation->token = Uuid::generate(5, $invitation->id, Uuid::NS_OID)->string;
        $invitation->save();

        // Log::info($status);

        return $invitation;
    }

    /**
     * Obtain an invitation by it's token
     */
    public static function get($token)
    {
        return self::where('token', $token)->first();
    }

    /**
     * Obtain invitations by their referrer team
     */
    public static function getByReferrerTeam($referrerTeam, $status = null)
    {
        return self::getByParticipant('team_id', $referrerTeam->id, $status);
    }

    /**
     * Obtain invitation by their referrer team
     */
    public static function getByReferrer($referrer, $status = null)
    {
        return self::getByParticipant('user_id', $referrer->id, $status);
    }

    /**
     * Obtain invitations by their invitee
     */
    public static function getByInvitee($invitee, $status = null)
    {
        return self::getByParticipant('invitee_id', $invitee->id, $status);
    }

    /**
     * Referrer Team
     */
    public function referrerTeam()
    {
        return $this->belongsTo(Spark::teamModel(), 'team_id');
    }

    /**
     * Referrer
     */
    public function referrer()
    {
        return $this->belongsTo(Spark::userModel(), 'user_id');
    }

    /**
     * Invitee
     */
    public function invitee()
    {
        return $this->belongsTo(Spark::userModel(), 'invitee_id');
    }

    /**
     * Status list
     */
    public function audit()
    {
        return $this->hasMany(SparkInvite::invitationStatusModel(), 'invitation_id')->orderBy('id', 'desc');
    }

    // Issue this invitation, may be performed automatically
    public function issue($team = null, $user = null, $notes = null)
    {
        if (!$this->isPending()) {
            // Log::warning("Attempted to accept an invitation for user {$this->invitee_id} that has the {$this->status} status.");
            return false;
        }

        return $this->setStatus(self::STATUS_ISSUED, $team, $user, $notes);
    }

    // Accept the invitation, this generates a password reset token but does not change the state of the invitation
    public function accept()
    {
        if (!$this->isIssued()) {
            // Log::warning("Attempted to issue an invitation for user {$this->invitee_id} that has the {$this->status} status.");
            return false;
        }

        $this->publishEvent('accepted');

        return Password::broker()->createToken($this->invitee);
    }

    // Reject the invitation, always assumed to be from the user
    public function reject()
    {
        return $this->setStatus(self::STATUS_REJECTED);
    }

    // Revoke the invitation
    public function revoke($team, $user, $notes = null)
    {
        return $this->setStatus(self::STATUS_REVOKED, $team, $user, $notes);
    }

    public function validate()
    {
        if (!$this->status) {
            return;
        }

        if ($this->status->state === self::STATUS_ISSUED) {
            if ($this->old_password && $this->invitee->password !== $this->old_password) {
                $this->setStatus(self::STATUS_SUCCESSFUL, null, null, 'Automated check');
                $this->cleanup();
                $this->publishEvent(self::STATUS_SUCCESSFUL);
                return;
            }

            if (Carbon::now()->diffInHours($this->status->created_at) >= config('sparkinvite.expires')) {
                $this->setStatus(self::STATUS_EXPIRED, null, null, 'Automated check');
                $this->cleanup();
                $this->publishEvent(self::STATUS_EXPIRED);
                return;
            }
        }
    }

    public function setStatus($status, $team = null, $user = null, $notes = null)
    {
        if (!in_array($status, self::STATUS)) {
            Log::error("Status {$status} is not valid.");
            return false;
        }

        if ($this->status) {
            $this->validate();
            switch ($this->status->state) {
                case self::STATUS_PENDING:
                    // OK to change, break and continue
                    break;
                case self::STATUS_ISSUED:
                    // OK to change, break and continue
                    break;
                default:
                    // Log::warning("Cannot change the status of invitation {$this->id} for user {$this->invitee_id} from {$this->status->state} to {$status}.");
                    return false;
            }
        }

        $current = InvitationStatus::make($this, $status, $team, $user, $notes);

        switch ($status) {
            case self::STATUS_SUCCESSFUL:
                $this->cleanup();
                break;
            case self::STATUS_REVOKED:
                $this->cleanup();
                break;
            case self::STATUS_REJECTED:
                $this->cleanup();
                break;
            case self::STATUS_EXPIRED:
                $this->cleanup();
                break;
            default:
                // Not an end state, so no clean up needed
                break;
        }

        $this->publishEvent($status);

        return $current;
    }

    /*
    |----------------------------------------------------------------------
    | Attributes
    |----------------------------------------------------------------------
    */

    /**
     * Status Attribute
     */
    public function getStatusAttribute()
    {
        return $this->audit()->first();
    }

    /*
    |----------------------------------------------------------------------
    | Private Methods
    |----------------------------------------------------------------------
    */
    private static function getByParticipant($column, $id, $state = null)
    {
        $query = self::where($column, $id);

        if ($state) {
            $query->whereHas('status', function ($status) use ($state) {
                $status->where('state', $state);
            });
        }

        return $query->latest()->get();
    }

    private function cleanup()
    {
        $this->old_password = null;
        $this->save();
    }

    /**
     * Fire Laravel event
     * @param  string $event event name
     */
    private function publishEvent($eventKey)
    {
        Event::fire(config('sparkinvite.event.prefix').".{$eventKey}", [
            'event' => $eventKey,
            'invitation' => $this
        ], false);
    }

    /*
    |----------------------------------------------------------------------
    | Magic Methods
    |----------------------------------------------------------------------
    */

    /**
     * Magic __call method to handle dynamic methods.
     *
     * @param  string $method
     * @param  array  $arguments
     * @return mixed
     */
    public function __call($method, $arguments = array())
    {
        // Handle isStatus() methods
        if (starts_with($method, 'is') && $method !== 'is') {
            $status = strtolower(substr($method, 2));

            if (in_array($status, self::STATUS)) {
                if (!$this->status) {
                    return false;
                }
                $this->validate();
                return  $this->status->state === $status;
            }
        }

        // Handle setStatus() methods
        if (starts_with($method, 'set') && $method !== 'set') {
            $status = strtolower(substr($method, 3));

            if (in_array($status, self::STATUS)) {
                return $this->setStatus(
                    $status,
                    array_key_exists('team', $arguments) ? $arguments['team'] : null,
                    array_key_exists('user', $arguments) ? $arguments['user'] : null,
                    array_key_exists('notes', $arguments) ? $arguments['notes'] : null
                );
            }
        }

        return parent::__call($method, $arguments);
    }
}
