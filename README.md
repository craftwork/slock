# Slock

Slock (short for session lock) is a decorator for the SessionInterface that implements session locking when the existing
implementation doesn't.

Unlike the default behavior provided by PHP, most of the popular frameworks provided by the PHP community forgo session
locking which creates potential race condition bugs with the session.

This library allows you to wrap any session handler provided by your favorite framework in an implementation which can
lock the session for you and remove the possibility of race conditions.

## Strategies

### FIFO queue

If a user makes, for example, three requests requiring the session then they'll be put in a stack one on top of the
other:

````
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
is put back on the ground for the next request to use. Then any other request that happens to notice can pick up the
key next.

The main difference is that with a semaphore there's no guaranteed order of execution (at least not with my
implementations), instead a random request will notice the key on the floor and take it.

### Which to use

The FIFO strategy is generally more talkative which means it's less likely to tolerate any communication issues such
as network faults. The semaphore strategy is less talkative, but you could have a dozen requests waiting to use the
session and since it's fairly arbitary who goes next, the most recent request could end up being processed before the
earliest.

Whilst in most normal web situations there should be fairly little chatter between browser and server, you should pick
the best solution for your scenario.