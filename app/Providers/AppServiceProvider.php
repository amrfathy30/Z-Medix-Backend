<?php

namespace App\Providers;

use App\Content\WebsiteContentDefinitions;
use App\Contracts\PhoneVerificationProviderInterface;
use App\Models\Admin;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\ContactMessage;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\PageSectionItem;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use App\Models\ReportCase;
use App\Models\Subject;
use App\Models\User;
use App\Policies\BlogCategoryPolicy;
use App\Policies\BlogPolicy;
use App\Policies\BookPagePolicy;
use App\Policies\BookPolicy;
use App\Policies\ChapterPagePolicy;
use App\Policies\ChapterPolicy;
use App\Policies\ContactMessagePolicy;
use App\Policies\FaqCategoryPolicy;
use App\Policies\FaqPolicy;
use App\Policies\PagePolicy;
use App\Policies\PageSectionItemPolicy;
use App\Policies\PageSectionPolicy;
use App\Policies\QuestionOptionPolicy;
use App\Policies\QuestionPolicy;
use App\Policies\QuizPolicy;
use App\Policies\ReportCasePolicy;
use App\Policies\SubjectPolicy;
use App\Services\Phone\Providers\TwilioVerifyProvider;
use App\Support\Content\Definitions\ContentDefinitionRegistry;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PhoneVerificationProviderInterface::class, function () {
            $provider = config('phone.default_provider', 'twilio_verify');

            return match ($provider) {
                'twilio_verify' => new TwilioVerifyProvider,
                default => throw new \InvalidArgumentException("Unknown phone verification provider: [{$provider}]."),
            };
        });

        $this->app->singleton(ContentDefinitionRegistry::class, function (): ContentDefinitionRegistry {
            $registry = new ContentDefinitionRegistry;

            foreach (WebsiteContentDefinitions::all() as $page) {
                $registry->register($page);
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            return URL::temporarySignedRoute(
                'public.auth.verify-email',
                now()->addMinutes(60),
                ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
            );
        });

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $base = rtrim((string) config('auth_features.password_reset.frontend_url', 'http://localhost:3000'), '/');
            $path = config('auth_features.password_reset.path', '/reset-password');

            return $base.$path.'?token='.$token.'&email='.urlencode($user->email);
        });

        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(PageSection::class, PageSectionPolicy::class);
        Gate::policy(PageSectionItem::class, PageSectionItemPolicy::class);
        Gate::policy(Blog::class, BlogPolicy::class);
        Gate::policy(BlogCategory::class, BlogCategoryPolicy::class);
        Gate::policy(Faq::class, FaqPolicy::class);
        Gate::policy(FaqCategory::class, FaqCategoryPolicy::class);
        Gate::policy(ContactMessage::class, ContactMessagePolicy::class);
        Gate::policy(Subject::class, SubjectPolicy::class);
        Gate::policy(Chapter::class, ChapterPolicy::class);
        Gate::policy(Book::class, BookPolicy::class);
        Gate::policy(BookPage::class, BookPagePolicy::class);
        Gate::policy(ChapterPage::class, ChapterPagePolicy::class);
        Gate::policy(Quiz::class, QuizPolicy::class);
        Gate::policy(Question::class, QuestionPolicy::class);
        Gate::policy(QuestionOption::class, QuestionOptionPolicy::class);
        Gate::policy(ReportCase::class, ReportCasePolicy::class);

        Relation::enforceMorphMap([
            'user' => User::class,
            'admin' => Admin::class,
            'page' => Page::class,
            'page_section' => PageSection::class,
            'page_section_item' => PageSectionItem::class,
            'blog' => Blog::class,
            'subject' => Subject::class,
            'chapter' => Chapter::class,
            'book' => Book::class,
            'book_page' => BookPage::class,
            'chapter_page' => ChapterPage::class,
            'quiz' => Quiz::class,
            'question' => Question::class,
            'question_option' => QuestionOption::class,
            'report_case' => ReportCase::class,
        ]);
    }
}
