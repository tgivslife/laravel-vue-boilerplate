import { api } from '@/plugins/axios'
import { ProblemDetailsError } from '@/services/ProblemDetailsError.js'
import { skeletonDelay } from '@/composables/useSkeletonDelay.js'

export default class HttpClient {
    get (path, params = {}, config = {}) {
        return this.#request(() => api.get(path, { params, ...config }))
    }

    post (path, body = {}, config = {}) {
        return this.#request(() => api.post(path, body, config))
    }

    put (path, body = {}, config = {}) {
        return this.#request(() => api.put(path, body, config))
    }

    patch (path, body = {}, config = {}) {
        return this.#request(() => api.patch(path, body, config))
    }

    delete (path, config = {}) {
        return this.#request(() => api.delete(path, config))
    }

    async #request (executor) {
        // No-op unless VITE_SKELETON_DELAY is set (dev knob for skeletons).
        await skeletonDelay()

        try {
            const { data } = await executor()

            // Newer list endpoints carry pagination in a sibling `meta` block
            // (JsonSuccessResponse); grafting it onto the unwrapped payload keeps
            // the historical `data`-only contract for every response without one.
            if (data?.meta !== undefined && data?.data !== undefined && !Array.isArray(data.data)) {
                return { ...data.data, meta: data.meta }
            }

            return data?.data ?? data
        } catch (error) {
            throw ProblemDetailsError.fromAxiosError(error)
        }
    }
}
