import axios from 'axios'

export class ProblemDetailsError extends Error {
    constructor ({ status, title, detail, instance, errors = [], cause }) {
        super(detail ?? title ?? 'Request failed')
        this.name = 'ProblemDetailsError'
        this.status = status
        this.title = title
        this.detail = detail
        this.instance = instance
        this.errors = errors
        this.cause = cause
    }

    get isNetworkError () {
        return this.status === undefined
    }

    get isValidationError () {
        return this.status === 422
    }

    get isUnauthenticated () {
        return this.status === 401
    }

    get isAlreadyAuthenticated () {
        return this.status === 409
    }

    toString () {
        const status = this.status ?? 'network'
        const summary = [this.title, this.detail].filter(Boolean).join(': ') || this.message
        const instance = this.instance ? ` (${this.instance})` : ''

        return `${this.name} [${status}] ${summary}${instance}`
    }

    static fromAxiosError (error) {
        if (axios.isCancel(error)) {
            return new ProblemDetailsError({ status: 0, cause: error })
        }

        if (!error.response) {
            return new ProblemDetailsError({ cause: error })
        }

        const { status, data } = error.response

        return new ProblemDetailsError({
            status,
            title: data?.title,
            detail: data?.detail ?? data?.message,
            instance: data?.instance,
            errors: data?.errors ?? [],
            cause: error,
        })
    }
}
