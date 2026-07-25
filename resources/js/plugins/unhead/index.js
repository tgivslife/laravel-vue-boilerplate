import { createHead } from '@unhead/vue/client'

export default function (app) {
    const head = createHead()
    app.use(head)
}
