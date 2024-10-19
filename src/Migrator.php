<?php
namespace Michaelbelgium\Mybbtoflarum;

use Carbon\Carbon;
use Flarum\User\User;
use Flarum\Tags\Tag;
use Flarum\Group\Group;
use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Migrator class
 * 
 * Connects to a mybb forum and migrates different elements
 */
class Migrator
{
    private $connection;
    private $db_prefix;
    private $mybb_path;
    private $count = [
        "users" => 0,
        "groups" => 0,
        "categories" => 0,
        "discussions" => 0,
        "posts" => 0,
        "attachments" => 0,
    ];

    const FLARUM_AVATAR_PATH = "assets/avatars/";
    const FLARUM_UPLOAD_PATH = "assets/files/";

    /**
     * Migrator constructor
     *
     * @param string $host
     * @param string $user 		
     * @param string $password
     * @param string $db
     * @param string $prefix
     * @param string $mybbPath
     */
    public function __construct(string $host, string $user, string $password, string $db, string $prefix, string $mybbPath = '')
    {
        // Create a DSN string for PostgreSQL
        $dsn = "pgsql:host=$host;dbname=$db";

        try {
            // Establish connection using PDO for PostgreSQL
            $this->connection = new \PDO($dsn, $user, $password);

            // Set the error mode to exception to handle errors
            $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Set the character encoding to UTF-8
            $this->connection->exec("SET NAMES 'UTF8'");
        } catch (\PDOException $e) {
            // Handle connection errors
            die("Error connecting to PostgreSQL: " . $e->getMessage());
        }

        // Set the database table prefix
        $this->db_prefix = $prefix;

        // Ensure the MyBB path ends with a '/'
        if (substr($mybbPath, -1) != '/')
            $mybbPath .= '/';

        $this->mybb_path = $mybbPath;
    }

//    function __destruct()
//    {
//        if(!is_null($this->getMybbConnection()))
//            $this->getMybbConnection()->close();
//    }

    /**
     * Migrate custom user groups
     */
    public function migrateUserGroups()
    {
        $groups = $this->getMybbConnection()->query("SELECT * FROM {$this->getPrefix()}usergroups WHERE type = 2")->fetchAll(\PDO::FETCH_OBJ);

        if(count($groups) > 0)
        {
            Group::where('id', '>', '4')->delete();

            foreach ($groups as $row)
            {
                $group = Group::build($row->title, $row->title, $this->generateRandomColor(), null);
                $group->id = $row->gid;
                $group->save();

                $this->count["groups"]++;
            }
        }
    }

    /**
     * Migrate users with their avatars and link them to their group(s)
     *
     * @param bool $migrateAvatars
     * @param bool $migrateWithUserGroups
     */
    public function migrateUsers(bool $migrateAvatars = false, bool $migrateWithUserGroups = false)
    {
        $this->disableForeignKeyChecks();
        
        $users = $this->getMybbConnection()->query("SELECT uid, username, lower(email) email, postnum, threadnum, to_timestamp( regdate ) AT TIME ZONE 'UTC' AS regdate, to_timestamp( lastvisit )  AT TIME ZONE 'UTC' AS lastvisit, usergroup, additionalgroups, avatar, lastip, password FROM {$this->getPrefix()}users WHERE uid > 1")
            ->fetchAll(\PDO::FETCH_OBJ);
        
        if(count($users) > 0)
        {
            User::query()->whereRaw('id>1')->delete();

            foreach ($users as $row)
            {
                $newUser = User::register(
                    $row->username, 
                    $row->email, 
                    ''
                );

                $newUser->activate();
                $newUser->id = $row->uid;
                $newUser->joined_at = $row->regdate;
                $newUser->last_seen_at = $row->lastvisit;
                $newUser->discussion_count = $row->threadnum;
                $newUser->comment_count = $row->postnum;
                if ($row->password){
                    $newUser->migratetoflarum_old_password = json_encode([
                        'type' => 'bcrypt',
                        'password' => $row->password,
                    ]);
                }

                if($migrateAvatars && !empty($this->getMybbPath()) && !empty($row->avatar))
                {
                    $fullpath = $this->getMybbPath().explode("?", $row->avatar)[0];
                    $avatar = basename($fullpath);
                    if(file_exists($fullpath) || true)
                    {
                        if(!file_exists(self::FLARUM_AVATAR_PATH))
                            mkdir(self::FLARUM_AVATAR_PATH, 0777, true);

                        if(copy($fullpath,self::FLARUM_AVATAR_PATH.$avatar))
                            $newUser->changeAvatarPath($avatar);
                    }
                }

                $newUser->save();

                if($migrateWithUserGroups)
                {
                    $userGroups = [(int)$row->usergroup];

                    if(!empty($row->additionalgroups))
                    {
                        $userGroups = array_merge(
                            $userGroups, 
                            array_map("intval", explode(",", $row->additionalgroups))
                        );
                    }

                    foreach($userGroups as $group)
                    {
                        if($group <= 7) continue;
                        $newUser->groups()->save(Group::find($group));
                    }
                }

                $this->count["users"]++;
            }
        }

        $this->enableForeignKeyChecks();
    }

    /**
     * Transform/migrate categories and forums into tags
     */
    public function migrateCategories()
    {
        $categories = $this->getMybbConnection()->query("SELECT fid, name, description, linkto, disporder, pid FROM {$this->getPrefix()}forums order by fid")
            ->fetchAll(\PDO::FETCH_OBJ);

        if(count($categories) > 0)
        {
            Tag::getQuery()->delete();

            foreach ($categories as $row)
            {
                if(!empty($row->linkto)) continue; //forums with links are not supported in flarum

                $tag = Tag::build($row->name, $this->slugTag($row->name), $row->description, $this->generateRandomColor(), null, false);

                $tag->id = $row->fid;
                $tag->position = (int)$row->disporder - 1;

                if($row->pid != 0)
                    $tag->parent()->associate(Tag::find($row->pid));

                $tag->save();

                $this->count["categories"]++;
            }
        }
    }

    /**
     * Migrate threads and their posts
     *
     * @param bool $migrateWithUsers Link with migrated users
     * @param bool $migrateWithCategories Link with migrated categories
     * @param bool $migrateSoftDeletedThreads Migrate also soft deleted threads from mybb
     * @param bool $migrateSoftDeletePosts Migrate also soft deleted posts from mybb
     */
    public function migrateDiscussions(
        bool $migrateWithUsers, bool $migrateWithCategories, bool $migrateSoftDeletedThreads, 
        bool $migrateSoftDeletePosts, bool $migrateAttachments
    ) {
        $migrateAttachments = class_exists('FoF\Upload\File') && $migrateAttachments;
        $migrateWithUsers = true;
        $migrateWithCategories = true;

        /** @var UrlGenerator $generator */
        $generator = resolve(UrlGenerator::class);
            
        $query = "SELECT tid, fid, subject, to_timestamp(dateline) AT TIME ZONE 'UTC' as dateline, uid, firstpost, to_timestamp(lastpost) AT TIME ZONE 'UTC' as lastpost, lastposteruid, closed, sticky, visible FROM {$this->getPrefix()}threads";
        if(!$migrateSoftDeletedThreads)
        {
            $query .= " WHERE visible != -1";
        }

        $threads = $this->getMybbConnection()->query($query)->fetchAll(\PDO::FETCH_OBJ);

        if(count($threads) > 0)
        {
            Discussion::getQuery()->whereRaw('id<359')->delete();
            Post::getQuery()->whereRaw('discussion_id<359')->delete();
            $usersToRefresh = [];

            foreach ($threads as $trow)
            {
                $tag = Tag::find($trow->fid);

                $discussion = new Discussion();
                $discussion->id = $trow->tid;
                $discussion->title = $trow->subject;

                if($migrateWithUsers)
                    $discussion->user()->associate(User::find($trow->uid));
                
                $discussion->slug = $this->slugDiscussion($trow->subject);
                $discussion->is_approved = true;
                $discussion->is_locked = $trow->closed == "1";
                $discussion->is_sticky = $trow->sticky;
                if($trow->visible == -1)
                    $discussion->hidden_at = Carbon::now();

                $discussion->save();

                $this->count["discussions"]++;

                if(!in_array($trow->uid, $usersToRefresh) && $trow->uid != 0)
                    $usersToRefresh[] = $trow->uid;

                $continue = true;

                if(!is_null($tag) && $migrateWithCategories)
                {
                    do {
                        $tag->discussions()->save($discussion);
    
                        if(is_null($tag->parent_id))
                            $continue = false;
                        else
                            $tag = Tag::find($tag->parent_id);
                        
                    } while($continue);
                }

                $query = "SELECT pid, tid, to_timestamp( dateline ) AT TIME ZONE 'UTC' as dateline, uid, message, visible FROM {$this->getPrefix()}posts WHERE tid = {$discussion->id}";
                if(!$migrateSoftDeletePosts)
                {
                    $query .= " AND visible != -1";
                }
                $query .= " order by pid";

                $posts = $this->getMybbConnection()->query($query)->fetchAll(\PDO::FETCH_OBJ);

                $number = 0;
                $firstPost = null;
                foreach ($posts as $prow)
                {
                    $user = User::find($prow->uid);

                    $post = CommentPost::reply($discussion->id, $prow->message, optional($user)->id, null);
                    $post->created_at = $prow->dateline;
                    $post->is_approved = true;
                    $post->number = ++$number;
                    if($prow->visible == -1)
                        $post->hidden_at = Carbon::now();

                    $post->save();

                    if($firstPost === null)
                        $firstPost = $post;

                    if(!in_array($prow->uid, $usersToRefresh) && $user !== null)
                        $usersToRefresh[] = $prow->uid;

                    $this->count["posts"]++;

                    if($migrateAttachments)
                    {                        
                        $attachments = $this->getMybbConnection()->query("SELECT uid, attachname, replace(replace(replace(unaccent(mybb_attachments.filename), '(', ''), ')', ''), ' ', '_') filename, filetype, filesize FROM {$this->getPrefix()}attachments WHERE pid = {$prow->pid}")->fetchAll(\PDO::FETCH_OBJ);

                        foreach ($attachments as $arow)
                        {
                            $filePath = $this->getMybbPath().'uploads/'.$arow->attachname;
                            $toFilePath = self::FLARUM_UPLOAD_PATH.'old/'.$prow->pid.$arow->filename;
                            $dirFilePath = dirname($toFilePath);

                            if(!file_exists($dirFilePath))
                                mkdir($dirFilePath, 0777, true);

                            if(!copy($filePath,$toFilePath)) continue;

                            $uploader = User::find($arow->uid);

                            if (str_starts_with($arow->filetype, 'image/')) {
                                $fileTemplate = new \FoF\Upload\Templates\ImagePreviewTemplate(
                                    resolve(Factory::class),
                                    resolve(TranslatorInterface::class)
                                );
                            } else {
                                $fileTemplate = new \FoF\Upload\Templates\FileTemplate(
                                    resolve(Factory::class),
                                    resolve(TranslatorInterface::class)
                                );
                            }

                            $file = new \FoF\Upload\File();
                            $file->actor()->associate($uploader);
                            //filename
                            $file->base_name = $arow->filename;
                            //cesta k souboru
                            $file->path = 'old/'.$prow->pid.$arow->filename;
                            $file->type = $arow->filetype;
                            $file->size = (int)$arow->filesize;
                            $file->upload_method = 'local';
                            $file->url = $generator->to('forum')->path('assets/files/old/'.$prow->pid.$arow->filename);
                            $file->uuid = Uuid::uuid4()->toString();
                            $file->tag = $fileTemplate;
                            $file->save();

                            $post->content = $post->content .' '. $fileTemplate->preview($file);
                            $post->save();

                            $file->posts()->save($post);

                            $this->count["attachments"]++;
                        }
                    }
                }

                if($firstPost !== null)
                    $discussion->setFirstPost($firstPost);
                
                $discussion->refreshCommentCount();
                $discussion->refreshLastPost();
                $discussion->refreshParticipantCount();

                $discussion->save();
            }

            if($migrateWithUsers)
            {
                foreach ($usersToRefresh as $userId) 
                {
                    $user = User::find($userId);
                    $user->refreshCommentCount();
                    $user->refreshDiscussionCount();
                    $user->save();
                }
            }
        }
    }

    private function enableForeignKeyChecks()
    {
        app('flarum.db')->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function disableForeignKeyChecks()
    {
        app('flarum.db')->statement('SET FOREIGN_KEY_CHECKS = 0');
    }

    /**
     * Generate a random color
     *
     * @return string
     */
    private function generateRandomColor(): string
    {
        return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }

    private function getPrefix(): string
    {
        return $this->db_prefix;
    }

    private function getMybbPath(): string
    {
        return $this->mybb_path;
    }

    private function getMybbConnection()
    {
        return $this->connection;
    }

    private function slugTag(string $value)
    {
        $slug = Str::slug($value);
        $count = Tag::where('slug', 'LIKE', $slug . '%')->get()->count();

        return $slug . ($count > 0 ? "-$count" : "");
    }

    private function slugDiscussion(string $value)
    {
        $slug = Str::slug($value);
        $count = Discussion::where('slug', 'LIKE', $slug . '%')->get()->count();

        return $slug . ($count > 0 ? "-$count": "");
    }

    public function getProcessedCount()
    {
        return $this->count;
    }
}
