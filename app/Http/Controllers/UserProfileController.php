<?php

namespace App\Http\Controllers;

use Auth;
use App\Album;
use App\Services\Artists\NormalizesArtist;
use App\Track;
use App\Soundkit;
use App\Loop;
use App\User;
use App\UserLimit;
use App\UserProfile;
use Common\Core\BaseController;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use League\ColorExtractor\Color;
use League\ColorExtractor\ColorExtractor;
use League\ColorExtractor\Palette;

class UserProfileController extends BaseController
{
    use NormalizesArtist;
    /**
     * @var Request
     */
    private $request;

    /**
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @param int $userId
     * @return JsonResponse
     */
    public function show($userId)
    {
        $user = app(User::class)
            ->with('profile', 'links', 'followers', 'followedUsers')
            ->withCount(['followers', 'followedUsers', 'likedLoops', 'uploadedLoops'])
            ->findOrFail($userId)
            ->setGravatarSize(220);

        if ( $user->settings && $user->settings->private && Auth::user()->id != $userId ) {
            $followers = [];
            foreach ($user->followers as $follower) {
                array_push($followers, $follower->id);
            }
            if (!array_search(Auth::user()->id, $followers)) {
                return $this->error('This profile is private. Only follwers can see it');
            }
        }

        if ( ! $user->getRelation('profile')) {
            $user->setRelation('profile', new UserProfile([
                'header_colors' => ['#a5d6a7', '#90caf9']
            ]));
        }

        $this->authorize('show', $user);

        $options = [
            'prerender' => [
                'view' => 'user.show',
                'config' => 'user.show'
            ]
        ];

        return $this->success([
            'user' => $user,
            'canUploadTracks' => $user->hasExactPermission('tracks.create'),
        ], 200, $options);
    }

    public function update(User $user)
    {
        $this->authorize('update', $user);

        $user->fill($this->request->get('user'))->save();
        $profileData = $this->request->get('profile');

        $profile = $user->profile()->updateOrCreate(['user_id' => $user->id], $profileData);

        $user->links()->delete();
        $links = $user->links()->createMany($this->request->get('links'));

        $user->setRelation('profile', $profile);
        $user->setRelation('links', $links);

        return $this->success(['user' => $user]);
    }

    public function limits(User $user)
    {
        return $this->success([
            'limits' => $user->limits ? $user->limits : [
                'dm' => 0,
                'uploads' => 0,
                'sell_soundkit' => 0,
                'sell_loop' => 0
            ]
        ]);
    }

    public function loadMore(User $user, $contentType)
    {
        switch ($contentType) {
            case 'uploadedTracks':
                $pagination = $this->uploadedTracks($user);
                break;
            case 'uploadedLoops':
                $pagination = $this->uploadedLoops($user);
                break;
            case 'likedLoops':
                $pagination = $this->likedLoops($user);
                break;
            case 'albums':
                $pagination = $this->albums($user);
                break;
            case 'playlists':
                $pagination = $user->playlists()->with('editors')->paginate(20);
                break;
            case 'reposts':
                $pagination = $user->reposts()->with('repostable')->paginate(20);
                break;
            case 'followers':
                $pagination = $user->followers()->paginate(20);
                break;
            case 'followedUsers':
                $pagination = $user->followedUsers()->paginate(20);
                break;
        }

        return $this->success(['pagination' => $pagination]);
    }

    private function uploadedTracks(User $user)
    {
        $pagination = $user->uploadedTracks()
            ->with('genres')
            ->withCount('plays')
            ->paginate(20);

        $trackUsers = collect([$user]);
        $pagination->transform(function (Track $track) use($trackUsers) {
            $track->setRelation('artists', $trackUsers);
            return $track;
        });
        return $pagination;
    }

    private function uploadedLoops(User $user)
    {
        $auth = Auth::user();
        $pagination = $user->uploadedLoops( $auth === NULL ? false : $user->id === $auth->id)
            ->with('genres')
            ->withCount('plays')
            ->paginate(5);

        $loopUsers = collect([$user]);
        $pagination->transform(function (Loop $loop) use($loopUsers) {
            $loop->setRelation('artists', $loopUsers);
            return $loop;
        });
        return $pagination;
    }

    private function likedLoops(User $user)
    {
        $pagination = $user->likedLoops()
            ->with(['genres', 'soundkit'])
            ->withCount('plays')
            ->paginate(5);

        $pagination->load('artists');
        return $pagination;
    }

    private function albums(User $user)
    {
        $pagination = $user->albums()
            ->withCount('plays')
            ->with(['tracks' => function(HasMany $query) {
                $query->orderBy('number', 'desc')
                    ->select('tracks.id', 'tracks.local_only', 'album_id', 'name', 'plays', 'image', 'url', 'duration');
            }])
            ->paginate(20);

        $pagination->transform(function(Album $album) use($user) {
            $album->setRelation('artist', $user);
            $album->tracks = $album->tracks->map(function (Track $track) use($user) {
                $track->setRelation('artists', collect([$user]));
                return $track;
            });
            return $album;
        });

        return $pagination;
    }
}
