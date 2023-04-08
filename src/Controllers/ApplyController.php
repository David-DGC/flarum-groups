<?php

namespace ClarkWinkelmann\GroupInvitation\Controllers;

use ClarkWinkelmann\GroupInvitation\Events\UsedInvitation;
use ClarkWinkelmann\GroupInvitation\Invitation;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApplyController implements RequestHandlerInterface
{
    protected Dispatcher $events;

    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $code = Arr::get($request->getQueryParams(), 'code');
        $action = Arr::get($request->getParsedBody(), 'action', 'join'); // Default action is 'join'

        /**
         * @var $invitation Invitation
         */
        $invitation = Invitation::query()->where('code', $code)->first();

        if (!$invitation) {
            throw new ValidationException([
                'code' => resolve(Translator::class)->trans('clarkwinkelmann-group-invitation.api.error.not-found'),
            ]);
        }

        if (!$invitation->hasUsagesLeft()) {
            throw new ValidationException([
                'code' => resolve(Translator::class)->trans('clarkwinkelmann-group-invitation.api.error.no-usages-left'),
            ]);
        }

        $actor = RequestUtil::getActor($request);

        $actor->assertCan('use', $invitation);

        if ($action === 'join') {
            if (!$actor->groups->contains('id', $invitation->group->id)) {
                $actor->groups()->save($invitation->group);

                $invitation->usage_count++;
                $invitation->save();

                $this->events->dispatch(new UsedInvitation($actor, $invitation));
            }
        } elseif ($action === 'leave') {
            if ($actor->groups->contains('id', $invitation->group->id)) {
                $actor->groups()->detach($invitation->group->id);

                // Dispatch an event if necessary (You will need to create the LeftGroup event class)
                // $this->events->dispatch(new LeftGroup($actor, $invitation));
            } else {
                throw new ValidationException([
                    'group' => resolve(Translator::class)->trans('clarkwinkelmann-group-invitation.api.error.not-member'),
                ]);
            }
        } else {
            throw new ValidationException([
                'action' => resolve(Translator::class)->trans('clarkwinkelmann-group-invitation.api.error.invalid-action'),
            ]);
        }

        return new EmptyResponse();
    }
}
