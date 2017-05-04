# Slock

[![Build Status](https://travis-ci.org/mhotchen/slock.svg?branch=master)](https://travis-ci.org/mhotchen/slock)

**NB:** This is a work in progress.

Slock (short for session lock) is a decorator for the SessionInterface that implements session locking when the existing
implementation doesn't.

Unlike the default behavior provided by PHP, most of the popular frameworks provided by the PHP community forgo session
locking which creates potential race condition bugs with the session.

This library allows you to wrap any session handler provided by your favorite framework in an implementation which can
lock the session for you and remove the possibility of race conditions.

Obviously these frameworks haven't forgone session locking for no reason. Distributed locks are a non-trial pursuit
which can have performance implications as well as undesired failure scenarios because of networking issues.

## Should I use this?

Here are two scenarios where session locking is useful, and hopefully you can interpret your own situation through
these scenarios to see if this library is useful for you:

### Scenario 1

A user on a ecommerce site confirms their purchase by clicking a "Confirm" button. In the background this takes a
payment from the user and accepts the order. After 10 seconds with nothing happening the user clicks the button
again.

Being a smart developer, your code will look for duplicate orders made recently and reject the new one until the
user confirms they wanted to make the same order twice. Unfortunately the first order hasn't yet reached your
server because it's held up somewhere higher up in the network.

#### Without locking

Both orders reach the code at roughly the same time, get data from the session, see no recent orders have been made,
and execute the order. One order finished before the other, writing to the session and finishes. The second order also
finishes and writes to the session, overriding the details of the first order.

#### With locking

Both orders reach the code at roughly the same time, and try to get the data from the session. One request locks the
session, causing the other to wait until the lock is released. It processes the order and returns, writes to the
session, unlocks it and finishes. The second order now locks the session, and looks for duplicate orders, finding the
order made from the first request and returns to the user asking if they really wanted to order twice.

### Scenario 2

You've built a single page application mail client which uses many simultaneous AJAX requests in the background to
retrieve data and process requests from the user. These requests are fairly independent of each other and largely, it
doesn't matter if two requests to send an email happen at once and override the session because the state is also
managed on the frontend. There could be some issues with the frontend not being exactly in sync with the order that
emails are sent, but this is largely superfluous for the user and will be updated in moments once the server-side
data is pushed back out to the client.

On starting up, around ten AJAX requests happen simultaneously to handle loading the data for several components within
the mail client.

#### Without locking

These requests are sent through simultaneously, with different servers and threads handling each request. Each request
grabs data from the session, some even write to the session but none of the data is critical and anything lost in the
session will simply show some outdated data to the user. This isn't a big issue because the data will also be retrieved
over a web socket connection later and pushed out to the user as updates happen.

Because all the requests can be handled simultaneously the client can load the application in under a second.

#### With session locking

These requests are sent through simultaneously, with different servers and threads handling each request. Each request
tries to get the session lock with only one managing to. The other nine wait in the queue for the session to be
unlocked.

Because no session data is ever lost/overridden the user sees fresh data, but each request happened one at a time so
the overall page loading time ballooned to several seconds.

## Strategies

### FIFO queue

If a user makes, for example, three requests requiring the session then they'll be put in a stack one on top of the
other:

```
+----------------------+
| fifo_queue_SESSIONID |
+----------------------+
| req1                 |
| req2                 |
| req3                 |
+----------------------+
```

The request at the top of the queue will continue to process. Once finished it will remove itself from the queue:

```
+----------------------+
| fifo_queue_SESSIONID |
+----------------------+      +----------------------+
                         ===> | req1                 |
+----------------------+      +----------------------+
| req2                 |
| req3                 |
+----------------------+
```

```
+----------------------+
| fifo_queue_SESSIONID |
+----------------------+
| req2                 |
| req3                 |
+----------------------+
```

New requests could come in and appear at the end of the queue:

```
+----------------------+
| fifo_queue_SESSIONID |
+----------------------+
| req2                 |
| req3                 |
| req4                 |
+----------------------+
```

Once a request sees itself at the front of the queue then it will be processed. This strategy works well if the order
that requests were made is important.

### Semaphore

Using a semaphore is more like having a key to a door on the floor. One request will come along, pick up the key, 
enter the room and lock the door behind it. Once it's finished in the room it will leave, lock the door, and the key
is put back on the ground for the next request to use. Then any other request that happens to come along can pick up the
key.

The main difference is that with a semaphore there's no guaranteed order of execution (at least not with my
implementations), instead a random request will notice the key on the floor and take it.

### Which to use

The FIFO strategy is generally more talkative which means it's less likely to tolerate any communication issues such
as network faults. The semaphore strategy is less talkative, but you could have a dozen requests waiting to use the
session and since it's fairly arbitary who goes next, the most recent request could end up being processed before the
earliest.

Whilst in most normal web situations there should be fairly little chatter between browser and server, you should pick
the best solution for your scenario.
