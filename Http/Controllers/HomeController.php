<?php

namespace App\Http\Controllers;

use Mail;
use App\Helper;
use App\Models\User;
use App\Models\Updates;
use App\Rules\TempEmail;
use App\Models\Bookmarks;
use App\Models\Categories;
use App\Models\StoryFonts;
use Illuminate\Http\Request;
use App\Models\AdminSettings;
use App\Models\LiveStreamings;
use League\Glide\ServerFactory;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Validator;
use League\Glide\Responses\SymfonyResponseFactory;

class HomeController extends Controller
{
  use Traits\Functions;

  public function __construct(Request $request, AdminSettings $settings)
  {
    $this->request = $request;
    try {
      // Check Datebase access
      $this->settings = $settings::first();
    } catch (\Exception $e) {
    }
  }

  /**
   * Homepage Section.
   *
   * @return \Illuminate\Http\Response
   */
  public function index()
  {
    try {
      // Check Datebase access
      $this->settings;
    } catch (\Exception $e) {
      // Redirect to Installer
      return redirect('install/script');
    }

    // Home Guest
    if (auth()->guest()) {
      if (config('settings.home_style') == 0) {
        $users = User::where('featured', 'yes')
          ->where('status', 'active')
          ->whereVerifiedId('yes')
          ->whereHideProfile('no')
          ->whereHas('plans', function ($query) {
            $query->where('status', '1');
          })
          ->where('id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
          ->with([
            'media' => fn($q) =>
            $q->select('type')
          ])
          ->orWhere('featured', 'yes')
          ->where('status', 'active')
          ->whereVerifiedId('yes')
          ->whereHideProfile('no')
          ->whereFreeSubscription('yes')
          ->where('id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
          ->inRandomOrder()
          ->with([
            'media' => fn($q) =>
            $q->select('type')
          ])
          ->paginate(6);

        $home = 'home';
      }

      // Total creators
      $usersTotal = User::whereStatus('active')
        ->whereVerifiedId('yes')
        ->whereHas('plans', function ($query) {
          $query->where('status', '1');
        })
        ->whereHideProfile('no')
        ->orWhere('status', 'active')
        ->whereVerifiedId('yes')
        ->whereFreeSubscription('yes')
        ->whereHideProfile('no')
        ->count();

      if (config('settings.home_style') == 1) {
        $home = 'home-login';
      }

      if (config('settings.home_style') == 2) {
        return $this->homeExplore();
      }

      return view('index.' . $home, [
        'users' => $users ?? null,
        'usersTotal' => $usersTotal
      ]);
    } else {

      $users = $this->userExplore();

      $updates = auth()->user()->feed();

      $stories = auth()->user()->stories();

      $storyFonts = StoryFonts::get(['id', 'name']);

      if ($storyFonts->count()) {
        foreach ($storyFonts as $font) {
          $fonts[] = str_replace('+', ' ', $font->name);
        }
        $fonts = implode("|", $fonts);
      }

      // Pay Per Views User
      $payPerViewsUser = auth()->user()->payPerView()->count();

      return view('index.home-session', [
        'users' => $users,
        'updates' => $updates,
        'hasPages' => $updates->hasPages(),
        'stories' => $stories,
        'fonts' => $fonts ?? null,
        'payPerViewsUser' => $payPerViewsUser ?? null
      ]);
    }
  }

  public function homeExplore()
  {
    $updates = Updates::verifyCountryBlocking()
      ->whereStatus('active')
      ->orderBy('updates.id', 'desc')
      ->getSelectRelations()
      ->simplePaginate(config('settings.number_posts_show'));

    return view('index.explore', [
      'updates' => $updates,
      'hasPages' => $updates->hasPages(),
      'users' => $this->userExplore(),
      'payPerViewsUser' => null
    ]);
  }

  public function ajaxUserUpdates()
  {
    $skip = $this->request->input('skip');

    $data = auth()->user()->feed($skip);

    return view('includes.updates', ['updates' => $data])->render();
  }

  public function getVerifyAccount($confirmation_code)
  {
    if (
      auth()->guest()
      || auth()->check()
      && auth()->user()->confirmation_code == $confirmation_code
      && auth()->user()->status == 'pending'
    ) {
      $user = User::where('confirmation_code', $confirmation_code)->where('status', 'pending')->first();

      if ($user) {
        User::where('confirmation_code', $confirmation_code)
          ->where('status', 'pending')
          ->update(['status' => 'active', 'confirmation_code' => '']);

        if ($this->settings->autofollow_admin) {
          // Auto-follow Admin
          $this->autoFollowAdmin($user->id);
        }

        auth()->loginUsingId($user->id);

        // Insert Login Session
        $this->loginSession($user->id);

        $redirect = $this->request->input('r') ?: '/';

        return redirect($redirect)
          ->with([
            'success_verify' => true,
          ]);
      } else {
        return redirect('/')
          ->with([
            'error_verify' => true,
          ]);
      }
    } else {
      return redirect('/');
    }
  } // End Method

  public function creators($type = false)
  {
    abort_if(config('settings.disable_creators_section'), 404);

    $query = trim($this->request->input('q'));

    if ($type && !in_array($type, ['featured', 'more-active', 'new', 'free'])) {
      abort(404);
    }

    switch ($type) {
      case 'featured':
        $orderBy = 'featured_date';
        $title = __('general.featured_creators');
        break;

      case 'more-active':
        $orderBy = 'COUNT(updates.id)';
        $title = __('general.more_active_creators');
        break;

      case 'new':
        $orderBy = 'id';
        $title = __('general.new_creators');
        break;

      case 'free':
        $orderBy = 'free_subscription';
        $title = __('general.creators_with_free_subscription');
        break;

      default:
        $orderBy = 'COUNT(subscriptions.id)';
        $title = __('general.explore_our_creators');
        break;
    }

    $resultShowByPage = 12;

    // Search Creator
    if (strlen($query) >= 3) {
      $title = __('general.search') . ' "' . $query . '"';

      $users = User::where('users.status', 'active')
        ->where('username', 'LIKE', '%' . $query . '%')
        ->whereVerifiedId('yes')
        ->where('id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
        ->whereRelation('plans', 'status', '1')
        ->whereFreeSubscription('no')
        ->whereHideProfile('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
        ->orWhere('users.status', 'active')
        ->whereVerifiedId('yes')
        ->where('id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
        ->whereFreeSubscription('yes')
        ->whereHideProfile('no')
        ->where('name', 'LIKE', '%' . $query . '%')
        ->whereHideName('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
        ->orderBy('featured_date', 'desc')
        ->with([
          'plans:id,status',
          'media' => fn($q) =>
          $q->select('type')
        ])
        ->simplePaginate($resultShowByPage);
    } else {

      if ($type == 'free') {
        $users = User::where('users.status', 'active')
          ->whereVerifiedId('yes')
          ->where('id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
          ->whereFreeSubscription('yes')
          ->whereHideProfile('no')
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

        $this->filterByGenderAge($users);

        $users = $users->orderBy(\DB::raw($orderBy), 'desc')
          ->with([
            'plans',
            'media' => fn($q) =>
            $q->select('type')
          ])
          ->simplePaginate($resultShowByPage);
      } else {

        $data = User::where('users.status', 'active');

        $whereRawFeatured = $type == 'featured' ? 'featured = "yes"' : 'users.status = "active"';

        $data->where('users.status', 'active')
          ->whereVerifiedId('yes')
          ->where('users.id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
          ->whereRelation('plans', 'status', '1')
          ->whereFreeSubscription('no')
          ->whereHideProfile('no')
          ->whereRaw($whereRawFeatured)
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

        $this->filterByGenderAge($data);

        $data->orWhere('users.status', 'active')
          ->whereVerifiedId('yes')
          ->where('users.id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
          ->whereFreeSubscription('yes')
          ->whereHideProfile('no')
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
          ->whereRaw($whereRawFeatured);

        $this->filterByGenderAge($data);

        if ($type == 'more-active') {
          $data->leftjoin('updates', 'updates.user_id', '=', 'users.id');
        }

        if (!$type) {
          $data->leftjoin('subscriptions', 'subscriptions.creator_id', '=', 'users.id');

          $data->orWhere('subscriptions.stripe_id', '=', '')
            ->where('ends_at', '>=', now())
            ->where('users.status', 'active')
            ->whereHideProfile('no')
            ->where('users.verified_id', 'yes')
            ->where('users.id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
            ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

          $this->filterByGenderAge($data);

          $data->orWhere('subscriptions.stripe_id', '<>', '')
            ->where('stripe_status', 'active')
            ->where('users.status', 'active')
            ->whereHideProfile('no')
            ->where('users.verified_id', 'yes')
            ->where('users.id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
            ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

          $this->filterByGenderAge($data);

          $data->orWhere('subscriptions.stripe_id', '=', '')
            ->whereFree('yes')
            ->where('users.status', 'active')
            ->whereHideProfile('no')
            ->where('users.verified_id', 'yes')
            ->where('users.id', '<>', config('settings.hide_admin_profile') == 'on' ? 1 : 0)
            ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

          $this->filterByGenderAge($data);
        }
        $users = $data->groupBy('users.id')
          ->orderBy(\DB::raw($orderBy), 'DESC')
          ->orderBy('users.id', 'ASC')
          ->select(
            'users.id',
            'users.name',
            'users.username',
            'users.avatar',
            'users.cover',
            'users.hide_name',
            'users.verified_id',
            'users.free_subscription',
            'users.featured',
            'users.story'
          )
          ->with([
            'plans',
            'media' => fn($q) =>
            $q->select('type')
          ])
          ->simplePaginate($resultShowByPage);
      }
    }
    if (request()->ajax()) {
      return view('includes.ajax-listing-creators', ['users' => $users])->render();
    }

    return view('index.creators', [
      'users' => $users,
      'title' => $title
    ]);
  }

  public function category($slug, $type = false)
  {
    $category = Categories::where('slug', $slug)->where('mode', 'on')->firstOrFail();
    $title = \Lang::has('categories.' . $category->slug) ? __('categories.' . $category->slug) : $category->name;

    switch ($type) {
      case 'featured':
        $orderBy = 'featured_date';
        $title = $title . ' - ' . __('general.featured_creators');
        break;

      case 'more-active':
        $orderBy = 'COUNT(updates.id)';
        $title = $title . ' - ' . __('general.more_active_creators');
        break;

      case 'new':
        $orderBy = 'id';
        $title = $title . ' - ' . __('general.new_creators');
        break;

      case 'free':
        $orderBy = 'free_subscription';
        $title = $title . ' - ' . __('general.creators_with_free_subscription');
        break;

      default:
        $orderBy = 'COUNT(subscriptions.id)';
        break;
    }

    if ($type == 'free') {
      $users = User::where('users.status', 'active')
        ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
        ->whereVerifiedId('yes')
        ->where('id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereFreeSubscription('yes')
        ->whereHideProfile('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

      $this->filterByGenderAge($users);

      $users = $users->orderBy($orderBy, 'desc')
        ->simplePaginate(12);
    } else {

      $data = User::where('users.status', 'active');

      $whereRawFeatured = $type == 'featured' ? 'featured = "yes"' : 'users.status = "active"';

      $data->where('users.status', 'active')
        ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
        ->whereVerifiedId('yes')
        ->where('users.id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereRelation('plans', 'status', '1')
        ->whereFreeSubscription('no')
        ->whereHideProfile('no')
        ->whereRaw($whereRawFeatured)
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

      $this->filterByGenderAge($data);

      $data->orWhere('users.status', 'active')
        ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
        ->whereVerifiedId('yes')
        ->where('users.id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereFreeSubscription('yes')
        ->whereHideProfile('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
        ->whereRaw($whereRawFeatured);

      $this->filterByGenderAge($data);

      if ($type == 'more-active') {
        $data->leftjoin('updates', 'updates.user_id', '=', 'users.id');
      }

      if (!$type) {
        $data->leftjoin('subscriptions', 'subscriptions.creator_id', '=', 'users.id');

        $data->orWhere('subscriptions.stripe_id', '=', '')
          ->where('ends_at', '>=', now())
          ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
          ->where('hide_profile', 'no')
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

        $this->filterByGenderAge($data);

        $data->orWhere('subscriptions.stripe_id', '<>', '')
          ->where('stripe_status', 'active')
          ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
          ->where('hide_profile', 'no')
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

        $this->filterByGenderAge($data);

        $data->orWhere('subscriptions.stripe_id', '<>', '')
          ->where('ends_at', '>=', now())
          ->where('stripe_status', 'canceled')
          ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
          ->where('hide_profile', 'no')
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

        $this->filterByGenderAge($data);

        $data->orWhere('subscriptions.stripe_id', '=', '')
          ->whereFree('yes')
          ->whereRaw("FIND_IN_SET(?, categories_id) > 0", [$category->id])
          ->where('hide_profile', 'no')
          ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

        $this->filterByGenderAge($data);
      }


      $users = $data->groupBy('users.id')
        ->orderBy(\DB::raw($orderBy), 'DESC')
        ->orderBy('users.id', 'ASC')
        ->select(
          'users.id',
          'users.name',
          'users.username',
          'users.avatar',
          'users.cover',
          'users.hide_name',
          'users.verified_id',
          'users.free_subscription',
          'users.featured'
        )
        ->with([
          'media' => fn($q) =>
          $q->select('type')
        ])
        ->simplePaginate(12);
    }

    if (request()->ajax()) {
      return view('includes.ajax-listing-creators', ['users' => $users])->render();
    }

    return view('index.categories', [
      'users' => $users,
      'title' => $title,
      'slug' => $slug,
      'image' => $category->image,
      'keywords' => $category->keywords,
      'description' => $category->description,
      'isCategory' => true,
    ]);
  }

  public function contact()
  {
    abort_if($this->settings->disable_contact, 404);

    return view('index.contact');
  }

  public function contactStore(Request $request)
  {
    $input = $request->all();
    $request['_captcha'] = config('settings.captcha_contact');

    $errorMessages = [
      'g-recaptcha-response.required_if' => 'reCAPTCHA Error',
      'g-recaptcha-response.captcha' => 'reCAPTCHA Error',
    ];

    Validator::extend('temp_email', function ($attribute, $value, $parameters) {
      return (new TempEmail())->passes($attribute, $value);
    }, (new TempEmail())->message());

    $validator = Validator::make($input, [
      'full_name' => 'min:3|max:25',
      'email'     => 'required|email:rfc,dns|temp_email',
      'subject'     => 'required',
      'message' => 'min:10|required',
      'g-recaptcha-response' => 'required_if:_captcha,==,on|captcha',
      'agree_terms_privacy' => 'required'
    ], $errorMessages);

    if ($validator->fails()) {
      return redirect('contact')
        ->withInput()->withErrors($validator);
    }

    // SEND EMAIL TO SUPPORT
    $fullname    = $input['full_name'];
    $email_user  = $input['email'];
    $title_site  = config('settings.title');
    $subject     = $input['subject'];
    $email_reply = config('settings.email_admin');

    try {
      Mail::send(
        'emails.contact-email',
        [
          'full_name' => $input['full_name'],
          'email' => $input['email'],
          'subject' => $input['subject'],
          '_message' => $input['message']
        ],
        function ($message) use (
          $fullname,
          $email_user,
          $title_site,
          $email_reply,
          $subject
        ) {
          $message->from($email_reply, $fullname);
          $message->subject(__('general.message') . ' - ' . $subject . ' - ' . $email_user);
          $message->to($email_reply, $title_site);
          $message->replyTo($email_user);
        }
      );
    } catch (\Exception $e) {
      return redirect('contact')->withInput()->withErrors($e->getMessage());
    }

    return redirect('contact')->with(['notification' => __('general.send_contact_success')]);
  }

  // Dark Mode
  public function darkMode($mode)
  {
    if ($mode == 'dark') {
      auth()->user()->dark_mode = 'on';
      auth()->user()->save();
    } else {
      auth()->user()->dark_mode = 'off';
      auth()->user()->save();
    }

    return redirect()->back();
  }

  // Add Bookmark
  public function addBookmark()
  {
    // Find post exists
    $post = Updates::findOrFail($this->request->id);

    $bookmark = Bookmarks::firstOrNew([
      'user_id' => auth()->user()->id,
      'updates_id' => $this->request->id
    ]);

    if ($bookmark->exists) {
      $bookmark->delete();

      return response()->json([
        'success' => true,
        'type' => 'deleted'
      ]);
    } else {
      $bookmark->save();

      return response()->json([
        'success' => true,
        'type' => 'added'
      ]);
    }
  } // End addBookmark

  public function searchCreator()
  {
    $query = $this->request->get('user');
    $data = "";

    if ($query != '' && strlen($query) >= 2) {
      $sql = User::where('status', 'active')
        ->where('username', 'LIKE', '%' . $query . '%')
        ->whereVerifiedId('yes')
        ->where('id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereRelation('plans', 'status', '1')
        ->whereFreeSubscription('no')
        ->whereHideProfile('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')

        ->orWhere('name', 'LIKE', '%' . $query . '%')
        ->whereVerifiedId('yes')
        ->where('id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereRelation('plans', 'status', '1')
        ->whereFreeSubscription('no')
        ->whereHideProfile('no')
        ->whereHideName('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')

        ->orWhere('status', 'active')
        ->where('username', 'LIKE', '%' . $query . '%')
        ->whereVerifiedId('yes')
        ->where('id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereFreeSubscription('yes')
        ->whereHideProfile('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')

        ->orWhere('status', 'active')
        ->where('name', 'LIKE', '%' . $query . '%')
        ->whereVerifiedId('yes')
        ->where('id', '<>', $this->settings->hide_admin_profile == 'on' ? 1 : 0)
        ->whereFreeSubscription('yes')
        ->whereHideProfile('no')
        ->whereHideName('no')
        ->where('blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%')
        ->orderBy('id', 'desc')
        ->take(4)
        ->get();

      if ($sql) {
        foreach ($sql as $user) {

          $name = $user->hide_name == 'yes' ? $user->username : $user->name;
          $description = $user->profession ?: '@' . $user->username;

          $data .= '<div class="card border-0">
  							<div class="list-group list-group-sm list-group-flush">
                 <a href="' . url($user->username) . '" class="list-group-item list-group-item-action text-decoration-none py-2 px-3 bg-autocomplete">
                   <div class="media">
                    <div class="media-left mr-3 position-relative">
                        <img class="media-object rounded-circle" src="' . Helper::getFile(config('path.avatar') . $user->avatar) . '" width="30" height="30">
                    </div>
                    <div class="media-body overflow-hidden">
                      <div class="d-flex justify-content-between align-items-center">
                       <h6 class="media-heading mb-0 text-truncate">
                            ' . $name . '
                        </h6>
                      </div>
  										<small class="text-truncate m-0 w-100 text-left d-block mt-1">' . $description . '</small>
                    </div>
                </div>
                  </a>
               </div>
  					 </div>';
        }
        return $data;
      }
    }
  } // End Method

  public function refreshCreators()
  {
    $type = $this->request->type == 'free' ?: false;
    $users = $this->userExplore($type);

    return view('includes.listing-explore-creators', ['users' => $users])->render();
  }

  public function creatorsBroadcastingLive()
  {
    // Search Live Streaming
    $users = LiveStreamings::whereType('normal')
      ->where('live_streamings.updated_at', '>', now()->subMinutes(5))
      ->leftjoin('users', 'users.id', '=', 'live_streamings.user_id')
      ->where('live_streamings.status', '0')
      ->where('users.blocked_countries', 'NOT LIKE', '%' . Helper::userCountry() . '%');

    $this->filterByGenderAge($users);

    $users = $users->orderBy('live_streamings.id', 'desc')
      ->select('live_streamings.*')
      ->paginate(20);

    if ($this->request->input('page') > $users->lastPage()) {
      abort('404');
    }

    return view('index.creators-live', [
      'users' => $users
    ]);
  }

  public function resizeImage($path, $size, $file)
  {
    try {
      $server = ServerFactory::create([
        'response' => new SymfonyResponseFactory(app('request')),
        'source' => Storage::disk()->getDriver(),
        'cache' => Storage::disk()->getDriver(),
        'source_path_prefix' => "uploads/{$path}",
        'cache_path_prefix' => '.cache',
        'base_url' => "uploads/{$path}",
        'group_cache_in_folders' => true
      ]);

      return $server->getImageResponse($file, [
        'w' => $size,
        'h' => request('crop') == 'fit' ? $size : false,
        'fit' => request('crop') == 'fit' ? 'crop-left' : false
      ]);
    } catch (\Exception $e) {
      if (isset($server)) {
        $server->deleteCache($file);
      }

      abort(404);
    }
  }

  public function imageBlur($path)
  {
    try {
      $server = ServerFactory::create([
        'response' => new SymfonyResponseFactory(app('request')),
        'source' => Storage::disk()->getDriver(),
        'cache' => Storage::disk()->getDriver(),
        'source_path_prefix' => '/uploads/updates/images/',
        'cache_path_prefix' => '.cache',
        'base_url' => '/uploads/updates/images/',
        'group_cache_in_folders' => true
      ]);

      return $server->getImageResponse($path, [
        'w' => 150,
        'h' => 150,
        'fit' => 'crop',
        'blur' => 85
      ]);
    } catch (\Exception $e) {
      if (isset($server)) {
        $server->deleteCache($path);
      }

      abort(404);
    }
  }
}
