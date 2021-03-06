<?php namespace DMA\Friends\Models;

use Event;
use Lang;
use Hashids\Hashids;
use DMA\Friends\Models\Settings;
use Illuminate\Database\QueryException;
use October\Rain\Auth\Models\Group as GroupBase;


/**
 * Friends User group model
 * @package DMA\Friends\Models
 * @author Carlos Arroyo
 *
 */
class UserGroup extends GroupBase{
    
    /**
     * @var string The database table used by the model.
     */
    protected $table = 'dma_friends_user_groups';
    
    /**
     * @var array Validation rules
     */
    public $rules = [
        'is_active' => 'boolean',
    ];
    
    /**
     * @var array Relations
     */
    public $belongsToMany = [
        'users' => ['Rainlab\User\Models\User', 
            'table'         => 'dma_friends_users_groups',
            'key'           => 'group_id',
            'otherKey'      => 'user_id',
            'timestamps'    => true,
            'pivot'         => ['membership_status']
        ]
    ];   
    
        
    /**
     * @var array Relations
     */
    public $belongsTo = [
        'owner' => ['Rainlab\User\Models\User', 'foreignKey' => 'owner_id']    
    ];
    
    public $morphMany = [
        'notifications'  => ['DMA\Notification\Models\Notification', 'name' => 'object'],
    ];
    
    
    /**
     * @var array Users of the group.
     */
    protected $groupUsers;    
    
    // CONSTANTS
    const MEMBERSHIP_PENDING   = 'PENDING';
    const MEMBERSHIP_ACCEPTED  = 'ACCEPTED';
    const MEMBERSHIP_REJECTED  = 'REJECTED';
    const MEMBERSHIP_CANCELLED = 'CANCELLED';
        

    /**
     * {@inheritDoc}
     */
    public static function boot()
    {
        parent::boot();
    
        // Setup event bindings...
        //UserGroup::observe(new UserGroupObserver);
        

        /**
         * Attach to the 'creating' Model Event to provide a UUID
         * for the `id` field (provided by $model->getKeyName())
         */
        static::saved(function ($model) {
            if(!$model->code){
                $model->code = $model->generateCode();
                $model->save();
            }
        });
    }
    
    
    /**
     * {@inheritDoc}
     */
    /*
    public function save(array $data = NULL, $sessionKey = NULL)
    {
        parent::save($data, $sessionKey);
        
        // Fire event group created
        $this->fireGroupEvent('created', array($this));
    }
    */
    
    /**
     * Fire an event and call the listeners.
     * @param string $event Event name
     * @param array $params Event parameters
     */
    protected function fireGroupEvent($event, $params = [])
    {
        // TODO : This method could be converted in a Trait 
        
        // Add event namespace
        $event = "dma.friends.group.$event";
        // Fire local event calling October Trait fireEvent
        //return $this->fireEvent($event, $params, $halt);
        // Fire Global event 
        Event::fire($event, $params);
    }
    
    /**
     * Mark inactive all open groups
     * @param unknown $datetime
     */
    public static function markInactiveGroups(){
        return self::where('is_active', '=', true)
        ->update(array('is_active' => false));
    }
    
    /**
     * Returns an array of users which the given group belongs to.
     * Only returns Pending and Active users.
     * 
     * @return array
     */
    public function getUsers()
    {
        if (!$this->groupUsers){
            $this->groupUsers = $this->users()->where(function($query){
                   $status = [ UserGroup::MEMBERSHIP_PENDING,
                               UserGroup::MEMBERSHIP_ACCEPTED
                        	];
                    $query->whereIn('membership_status', $status);
            })->get();  
        }          
            
        return $this->groupUsers;
    }
    
    /**
     * Create a new user group
     * 
     * @param RainLab\User\Models\User $user
     * @param string $name name of the group
     * @return DMA\Friends\Models\UserGroup
     */
    public static function createGroup($user, $name)
    {
        $activeGroups = self::where('owner_id', $user->getKey())
                                ->isActive()->count();
               
        if ($activeGroups < Settings::get('maximum_groups_own_per_user')) {
        
            $group = new self();
            $group->owner = $user;
            $group->name = $name;
            
            if($group->save()) {
                \Postman::send('group-creation', function($notification) use ($user, $user, $group){
                    $notification->to($user, $user->name);
                    $notification->subject("Group '$group->name' created");
                    $notification->message('You have succesfully create a new group');
                    $notification->attachObject($group);
                }, ['kiosk', 'flash']);
            }
                        
            return $group;
        }else{
            $message = Lang::get('dma.friends::lang.exceptions.limitUserOwnGroups');
            throw new \Exception($message);
        }
    }
    
    
    /**
     * Generate a unique code for this group.
     * This code can be used to join this group
     * @return string
     */
    public function generateCode()
    {
        $hashids = new Hashids('friends', 6, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890');
        $id = $this->getKey();
        $owner_id = $this->owner->getKey();
        $code = $hashids->encode($id, $owner_id);
        return $code;
    }
    
    /**
     * Join user to a given group using its code
     * @param sting $code Group code
     * @param RainLab\User\Models\User $user
     * 
     * @return DMA\Friends\Models\UserGroup
     */
    
    public static function joinByCode($code, $user)
    {
        $group = static::where('code', $code)->firstOrFail();
        $group->addUser($user);
        $group->acceptMembership($user);
        return $group;
    }
    
    /**
     * Adds the user to the group.
     * @param RainLab\User\Models\User $user
     * @return bool
     */
    public function addUser(&$user)
    {

        if (count($this->getUsers()) < Settings::get('maximum_users_per_group')
            && $this->is_active ){
            if (!$this->inGroup($user) && 
                    $this->owner->getKey() != $user->getKey()) {
                
                // Test if the given user has available memberships 
                if(!self::hasAvailableMemberships($user))
                    return false;
                
                try{
                    $this->users()->attach($user);
                    $this->groupUsers = null;
                    
                }catch(QueryException $e){
                    // SQL integrity user and group relation already exists.
                    if ($e->getCode() == 23000){
                        // User was part of the group but rejected or cancel
                        // the membership. As the user is not part of other group
                        // yet, the creator is able to re-invite user to join the group
                        $this->setMembershipStatus($user, self::MEMBERSHIP_PENDING, $testUserInGroup=false);
                    }
                }
                
                $this->sendNotification($user, 'group-request', ['mail', 'kiosk', 'flash']);
                
                // Fire event 
                $this->fireGroupEvent('user.added', array($this, $user));
                
                return true;
            }
        }else{
            $message = Lang::get('dma.friends::lang.exceptions.limitGroupUsers');
            throw new \Exception($message);
        }

        return false;
    }
    
    /**
     * Removes the user from the group.
     * @param RainLab\User\Models\User $user
     * @return bool
     */
    public function removeUser($user)
    {
        if ($this->inGroup($user, $includeAll=true)) {
            $this->users()->detach($user);
            $this->groupUsers = null;
            
            // Fire event
            $this->fireGroupEvent('user.removed', array($this, $user));
            
            return true;
        }
    
        return false;
    }
    
    /**
     * See if the user is in the group.
     * @param RainLab\User\Models\User $user
     * @params boolean if True check all users attach to the group regarless of the status.
     *                    if True check only filtered users returned by getUsers method.
     * @return bool
     */
    public function inGroup($user, $includeAll=false)
    {
        $users = $this->getUsers();
        if ($includeAll) $users = $this->users()->get();
        
        foreach ($users as $u) {
            if ($u->getKey() == $user->getKey())
                return true;
        }
    
        return false;
    }    
        
    // TODO : Group acceptance maybe should be move to an extend 
    // version of RainLab\User\Models\User version

    /**
     * User accept invite
     * @param RainLab\User\Models\User $user
     * @return bool
     */
    public function acceptMembership(&$user)
    {
        // Test if the given user has available memberships
        if(!self::hasAvailableMemberships($user))
            return false;        
        $status = $this->setMembershipStatus($user, self::MEMBERSHIP_ACCEPTED);
        return $status == self::MEMBERSHIP_ACCEPTED;
    }

    /**
     * User accept invite
     * @param RainLab\User\Models\User $user
     * @return bool
     */
    public function rejectMembership(&$user)
    {
        $status = $this->setMembershipStatus($user, self::MEMBERSHIP_REJECTED);
        return $status == self::MEMBERSHIP_REJECTED;
    }    

    /**
     * User accept invite
     * @param RainLab\User\Models\User $user
     * @return bool
     */
    public function cancelMembership(&$user)
    {            
        $status = $this->setMembershipStatus($user, self::MEMBERSHIP_CANCELLED);
        return $status == self::MEMBERSHIP_CANCELLED;        
    }
        
    /**
     * Change membership status, send notification.  
     * @param RainLab\User\Models\User $user
     * @param string $status PENDING, ACCEPTED, REJECTED, CANCELLED
     * @param boolean $testUserInGroup If true only allow to change status to active or pending users. 
     *                                 If false ignore this restriction. 
     * @return string
     */
    protected function setMembershipStatus(&$user, $status, $testUserInGroup=true)
    {
        if ($this->inGroup($user, $includeAll=!$testUserInGroup)){
            
            // FIXME : See comments in loadPivot method
            $this->loadPivot($user);
            
            if ($user->pivot->membership_status != $status){
                $user->pivot->membership_status = $status;
                $user->pivot->save();
                
                // Clean cache of groups
                $this->groupUsers = null;
                
                // Send notification to group owners if the status
                // is different to PENDING. That notification is send by addUser.
                if ($status != self::MEMBERSHIP_PENDING){
                    $notificationName = 'group-' . strtolower($status);
                    $this->sendNotification($this->owner, $notificationName, ['mail', 'kiosk']);
                    
                    // Fire event
                    $event = strtolower($status);
                    $this->fireGroupEvent("invite.$event", array($this, $user));
                }
            }else{
                // Return current user status
                return $user->pivot->membership_status; 
            }
            return $status;
        }
    }

    /**
     * Test if user has active memberships
     * 
     * @param RainLab\User\Models\User $user
     * @return boolean
     */
    public static function hasActiveMemberships($user){
        return self::getActiveMembershipsCount($user) > 0;
    }  

    /**
     * Test if user has available memberships left
     *
     * @param RainLab\User\Models\User $user
     * @return boolean
     */
    public static function hasAvailableMemberships($user){
        $memberships = self::getActiveMembershipsCount($user);
        return $memberships < Settings::get('maximum_user_group_memberships');
    }
    
    
    
    /**
     * Count active memberships for a given user
     *
     * @param RainLab\User\Models\User $user
     * @return integer
     */
    public static function getActiveMembershipsCount($user){
        $status = self::MEMBERSHIP_ACCEPTED;
        return self::where('is_active', true)
                ->with('users')
                ->whereHas('users', function($query) use ($user, $status){
                    $query->where('membership_status', $status)
                    ->where('user_id', $user->getKey());
                }
        )->count();
    
    }
    
    

   
    /**
     * 
     * @param RainLab\User\Models\User $user $user
     * @param string $notificationName
     * @param array $channel
     * @return boolean|Ambigous <boolean, void>
     */
    public function sendNotification(&$user, $notificationName, array $channel=null)
    {
        // Check if the user is part of the group or the owner.
        if (!$this->inGroup($user)) 
            if($this->owner->getKey() != $user->getKey()) 
                return false;
            
        $group      = $this;
        $fromUser   = $this->owner;
        \Postman::send($notificationName, function($notification) use ($user, $fromUser, $group){
        	$notification->to($user, $user->name);
        	$notification->from($fromUser);
        	$notification->subject('Groups');
        	$notification->attachObject($group);
        }, $channel);
        
        return false;
    }
    
       
    /**
     * This method is for internal use as a temporal solution for empty pivot variables. 
     */
    private function loadPivot(&$user){
        // FIXME : Sometimes pivot is empty but I didn't find why it happens.
        // I am suspecting that is an internal problem in the Eloquent model and how it gets updated
        // after a new relationship is created.
        // For now the solution is if the pivot is empty reload the model.
        if (is_null($user->pivot)){
        	$user = $this->users()->where('user_id', $user->getKey())->get()[0];
        }    
        return $user;    
    }
    
    
    /**
     * Scope to return only active groups
     * @param  $query
     */
    public function scopeIsActive($query){
        return $query->where('is_active', true);
    }

}


class UserGroupObserver {

    public function saving($model)
    {
        if(!$model->is_active) return false;
    }

    public function updating($model)
    {
        if(!$model->is_active) return false;
    }
    
}