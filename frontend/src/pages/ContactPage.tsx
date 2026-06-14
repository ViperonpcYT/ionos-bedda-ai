import { Mail } from 'lucide-react';
import { Link } from 'react-router-dom';
import { PageMeta } from '@/components/seo/PageMeta';

export function ContactPage() {
  return (
    <>
      <PageMeta
        title="Support | OnlyBikes"
        description="Contact OnlyBikes for fitment, order, and shipping questions."
        canonicalPath="/contact.html"
      />
      <main className="mx-auto max-w-3xl px-4 py-14">
        <p className="ob-badge">Support</p>
        <h1 className="font-display mt-4 text-5xl uppercase">
          We reply before you buy.
        </h1>
        <p className="mt-6 leading-7 text-zinc-400">
          Fitment questions, order status, shipping — send a message and include
          your bike model if you are unsure about a part.
        </p>
        <div className="ob-card mt-8 p-6">
          <p className="text-sm uppercase tracking-wide text-zinc-400">Email</p>
          <p className="mt-2 flex items-center gap-2 text-xl font-bold">
            <Mail className="h-5 w-5 text-green-400" aria-hidden />
            <a
              className="text-green-300 hover:underline"
              href="mailto:support@onlybikes.shop"
            >
              support@onlybikes.shop
            </a>
          </p>
          <p className="mt-4 text-sm text-zinc-500">
            Typical reply within one business day. Include your order number for
            faster help.
          </p>
        </div>
        <div className="mt-6 grid gap-4 md:grid-cols-2">
          <Link className="ob-card p-5" to="/fitment">
            <b>Fitment guide</b>
            <p className="mt-2 text-sm text-zinc-400">
              Confirm compatibility before ordering.
            </p>
          </Link>
          <Link className="ob-card p-5" to="/returns">
            <b>No-return policy</b>
            <p className="mt-2 text-sm text-zinc-400">
              All sales final — read before checkout.
            </p>
          </Link>
        </div>
      </main>
    </>
  );
}
