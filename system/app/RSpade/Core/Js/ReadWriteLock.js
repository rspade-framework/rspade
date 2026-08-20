/**
 * ReadWriteLock implementation for RSpade framework
 * Provides exclusive (write) and shared (read) locking mechanisms for asynchronous operations
 */
class ReadWriteLock {
    static #locks = new Map();

    /**
     * Get or create a lock object for a given name
     * @private
     */
    static #get_lock(name) {
        let s = this.#locks.get(name);
        if (!s) {
            s = { readers: 0, writer_active: false, reader_q: [], writer_q: [] };
            this.#locks.set(name, s);
        }
        return s;
    }

    /**
     * Schedule the next operation for a lock
     * @private
     */
    static #schedule(name) {
        const s = this.#get_lock(name);
        if (s.writer_active || s.readers > 0) return;

        // run one writer if queued
        if (s.writer_q.length > 0) {
            const { cb, resolve, reject } = s.writer_q.shift();
            s.writer_active = true;
            Promise.resolve()
                .then(cb)
                .then(resolve, reject)
                .finally(() => {
                    s.writer_active = false;
                    this.#schedule(name);
                });
            return;
        }

        // otherwise run all queued readers in parallel
        if (s.reader_q.length > 0) {
            const batch = s.reader_q.splice(0);
            s.readers += batch.length;
            for (const { cb, resolve, reject } of batch) {
                Promise.resolve()
                    .then(cb)
                    .then(resolve, reject)
                    .finally(() => {
                        s.readers -= 1;
                        if (s.readers === 0) this.#schedule(name);
                    });
            }
        }
    }

    /**
     * Acquire an exclusive mutex lock by name.
     * Only one writer runs at a time; blocks readers until finished.
     * @param {string} name
     * @param {() => any|Promise<any>} cb
     * @returns {Promise<any>}
     */
    static acquire(name, cb) {
        return new Promise((resolve, reject) => {
            const s = this.#get_lock(name);
            s.writer_q.push({ cb, resolve, reject });
            this.#schedule(name);
        });
    }

    /**
     * Acquire a shared read lock by name.
     * Multiple readers can run in parallel; blocks when writer is active.
     * @param {string} name
     * @param {() => any|Promise<any>} cb
     * @returns {Promise<any>}
     */
    static acquire_read(name, cb) {
        return new Promise((resolve, reject) => {
            const s = this.#get_lock(name);
            if (s.writer_active || s.writer_q.length > 0) {
                s.reader_q.push({ cb, resolve, reject });
                return this.#schedule(name);
            }
            s.readers += 1;
            Promise.resolve()
                .then(cb)
                .then(resolve, reject)
                .finally(() => {
                    s.readers -= 1;
                    if (s.readers === 0) this.#schedule(name);
                });
        });
    }

    /**
     * Force-unlock a mutex (use with caution).
     * Completely removes the lock state, potentially breaking waiting operations.
     * @param {string} name
     */
    static force_unlock(name) {
        this.#locks.delete(name);
    }

    /**
     * Get information about pending operations on a mutex.
     * @param {string} name
     * @returns {{readers: number, writer_active: boolean, reader_q: number, writer_q: number}}
     */
    static pending(name) {
        const s = this.#locks.get(name);
        if (!s) return { readers: 0, writer_active: false, reader_q: 0, writer_q: 0 };
        return {
            readers: s.readers,
            writer_active: s.writer_active,
            reader_q: s.reader_q.length,
            writer_q: s.writer_q.length
        };
    }
}