import { AnimatePresence, LayoutGroup, motion } from 'motion/react'
import { ArrowUpDown, Clock, Flame, History, RefreshCw, Search, SearchX, Sparkles, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { errorMessage, firstFieldError, isApiError } from '@/api/errors'
import { useLeaderboard } from '@/api/hooks/useLeaderboard'
import { useSearch, type SearchSort } from '@/api/hooks/useSearch'
import { useTopics } from '@/api/hooks/useTopics'
import type { ProductCard } from '@/data/types'
import { relativeDay } from '@/lib/date'
import { clearRecent, loadRecent, rememberSearch } from '@/lib/recentSearches'
import { useCooldown } from '@/lib/useCooldown'
import { useDebouncedValue } from '@/lib/useDebouncedValue'
import { cardTopics } from '@/components/product/cardTopics'
import { ProductLogo } from '@/components/product/ProductLogo'
import { TopicChip } from '@/components/product/TopicChip'
import { VoteButton } from '@/components/product/VoteButton'
import { AnimatedNumber, Button, EmptyState, ErrorState, LoadMore, Skeleton, Tabs, type TabItem, cn } from '@/components/ui'
import { Highlight, snippet, tokenize } from './components/Highlight'
import { TopicFilter } from './components/TopicFilter'

const SORTS: SearchSort[] = ['relevance', 'votes', 'newest']
const DEBOUNCE_MS = 250
const MIN_QUERY = 2
const MAX_QUERY = 100

function SearchResult({ product, tokens }: { product: ProductCard; tokens: string[] }) {
  const inTitle = tokens.some((t) => `${product.name} ${product.tagline}`.toLowerCase().includes(t))
  const excerpt = tokens.length && !inTitle ? snippet(product.description ?? '', tokens) : null
  return (
    <div className="group surface relative flex items-center gap-3 p-3 transition-all hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lift sm:gap-4 sm:p-4 dark:hover:border-brand-500/30">
      <Link to={`/p/${product.slug}`} aria-label={product.name} className="absolute inset-0 z-0 rounded-2xl" />
      <div className="transition-transform duration-300 group-hover:-rotate-6 group-hover:scale-105">
        <ProductLogo name={product.name} src={product.logoUrl} hue={product.hue} />
      </div>
      <div className="min-w-0 flex-1">
        <h3 className="truncate font-sans text-[15px] font-bold tracking-normal group-hover:text-brand-600">
          <Highlight text={product.name} tokens={tokens} />
        </h3>
        <p className="mt-0.5 line-clamp-1 text-sm text-ink-500">
          <Highlight text={product.tagline} tokens={tokens} />
        </p>
        {excerpt && (
          <p className="mt-1 line-clamp-2 text-xs text-ink-400">
            <Highlight text={excerpt} tokens={tokens} />
          </p>
        )}
        <div className="pointer-events-none relative z-10 mt-2 flex flex-wrap items-center gap-1.5 [&_a]:pointer-events-auto">
          <span className="inline-flex items-center gap-1 text-xs text-ink-400">
            <Clock className="size-3.5" /> {product.launchDate ? relativeDay(product.launchDate) : ''}
          </span>
          <span className="text-ink-300">·</span>
          {cardTopics(product).map((t) => (
            <TopicChip key={t.id} topic={t} />
          ))}
        </div>
      </div>
      <div className="relative z-10">
        <VoteButton product={product} />
      </div>
    </div>
  )
}

/** Why a search failed: a 422 says what to fix, a timeout says when to retry. */
function SearchError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const wait = useCooldown()
  const timeout = isApiError(error) && error.code === 'search_timeout'
  const retryAfter = isApiError(error) ? error.retryAfter : undefined
  useEffect(() => {
    if (timeout) wait.start(retryAfter ?? 5)
  }, [timeout, retryAfter, wait.start]) // eslint-disable-line react-hooks/exhaustive-deps

  if (isApiError(error) && error.status === 422) {
    return <EmptyState icon={<SearchX className="size-6" />} title="Check your search" body={firstFieldError(error, 'q') ?? firstFieldError(error, 'topic') ?? firstFieldError(error, 'sort') ?? errorMessage(error)} />
  }
  if (timeout) {
    return (
      <EmptyState
        icon={<Clock className="size-6" />}
        title="That search took too long"
        body={`Try fewer words, or try again${retryAfter ? ` in ${retryAfter} s` : ' in a moment'}.`}
        action={
          <Button variant="outline" disabled={wait.remaining > 0} icon={<RefreshCw className="size-4" />} onClick={onRetry}>
            {wait.remaining > 0 ? `Retry in ${wait.remaining} s` : 'Retry'}
          </Button>
        }
      />
    )
  }
  return <ErrorState error={error} onRetry={onRetry} title="Search is unavailable" />
}

export default function SearchPage() {
  const [params, setParams] = useSearchParams()
  const q = params.get('q') ?? ''
  const topicSlug = params.get('topic') ?? ''
  const sortParam = params.get('sort')
  const inputRef = useRef<HTMLInputElement>(null)
  const [recent, setRecent] = useState<string[]>(loadRecent)

  const term = useDebouncedValue(q, DEBOUNCE_MS).trim()
  const hasQuery = term.length >= MIN_QUERY
  const topics = useTopics()
  const topic = topics.data?.find((t) => t.slug === topicSlug)
  // An unknown slug would be a 422, so once the topics are known it is simply no filter. While they load nothing is asked.
  const topicParam = !topicSlug ? undefined : topic ? topic.slug : topics.isError ? topicSlug : undefined
  const topicReady = !topicSlug || topics.isSuccess || topics.isError
  const active = hasQuery || !!topicParam
  // `relevance` needs a query (the server answers 422 otherwise); a query defaults to it, a topic alone to most voted.
  const requested = SORTS.find((x) => x === sortParam)
  const sort: SearchSort = requested ? (requested === 'relevance' && !hasQuery ? 'votes' : requested) : hasQuery ? 'relevance' : 'votes'

  const search = useSearch({ q: hasQuery ? term : undefined, topic: topicParam, sort }, active && topicReady)
  const results = search.items
  // The highlight follows the server's normalised phrase of the rows on screen.
  const tokens = tokenize(search.data?.pages[0]?.meta?.query ?? '')
  const popular = useLeaderboard('all', undefined, !active)

  const setParam = (key: string, value: string, fallback = '') => {
    const next = new URLSearchParams(params)
    if (!value || value === fallback) next.delete(key)
    else next.set(key, value)
    setParams(next, { replace: true })
  }

  // Save a search after the user pauses typing.
  useEffect(() => {
    if (q.trim().length < MIN_QUERY) return
    const t = setTimeout(() => setRecent((r) => rememberSearch(r, q)), 1200)
    return () => clearTimeout(t)
  }, [q])

  const sortTabs: TabItem<SearchSort>[] = [
    ...(hasQuery ? [{ value: 'relevance' as const, label: 'Best match' }] : []),
    { value: 'votes', label: 'Most voted' },
    { value: 'newest', label: 'Newest' },
  ]
  const stale = search.isPlaceholderData

  return (
    <div className="mx-auto max-w-3xl">
      {/* Search box */}
      <motion.div initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} className="relative">
        <Search className="pointer-events-none absolute top-1/2 left-5 size-6 -translate-y-1/2 text-ink-400" />
        <input
          ref={inputRef}
          autoFocus
          type="search"
          maxLength={MAX_QUERY}
          value={q}
          onChange={(e) => setParam('q', e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') setRecent((r) => rememberSearch(r, q))
            if (e.key === 'Escape') setParam('q', '')
          }}
          placeholder="Search products, ideas, makers' pitches…"
          aria-label="Search products"
          className="h-16 w-full rounded-2xl border border-ink-200 bg-white pr-14 pl-14 text-lg font-medium shadow-soft transition placeholder:text-ink-400 focus:border-brand-400 focus:shadow-lift focus:ring-4 focus:ring-brand-500/15 focus:outline-none dark:border-white/10 dark:bg-ink-900 [&::-webkit-search-cancel-button]:hidden"
        />
        <AnimatePresence>
          {q && (
            <motion.button
              initial={{ opacity: 0, scale: 0.6 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.6 }}
              onClick={() => {
                setParam('q', '')
                inputRef.current?.focus()
              }}
              aria-label="Clear search"
              className="absolute top-1/2 right-4 flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full bg-ink-100 text-ink-500 hover:bg-ink-200 dark:bg-white/10"
            >
              <X className="size-4" />
            </motion.button>
          )}
        </AnimatePresence>
      </motion.div>

      {q.trim().length > 0 && q.trim().length < MIN_QUERY && !topicParam && <p className="mt-2 pl-1 text-sm text-ink-500">Type at least two letters to search.</p>}

      <TopicFilter value={topicSlug} onChange={(s) => setParam('topic', s)} className="mt-4" />

      {active ? (
        <>
          <div className="mt-6 mb-3 flex flex-wrap items-center justify-between gap-3">
            <p className={cn('text-sm text-ink-500 transition-opacity', stale && 'opacity-60')}>
              {search.isSuccess && (
                <>
                  <AnimatedNumber value={results.length} className="font-bold text-ink-900 dark:text-white" />
                  {search.hasNextPage ? '+' : ''} result{results.length === 1 && !search.hasNextPage ? '' : 's'}
                </>
              )}
              {hasQuery && (
                <>
                  {' '}for <b className="text-ink-800 dark:text-ink-100">“{term}”</b>
                </>
              )}
              {topic && <> in {topic.emoji} {topic.name}</>}
            </p>
            <div className="flex items-center gap-2">
              <ArrowUpDown className="size-4 text-ink-400" />
              <Tabs<SearchSort> items={sortTabs} value={sort} onChange={(v) => setParam('sort', v, hasQuery ? 'relevance' : 'votes')} />
            </div>
          </div>

          {search.isError && !search.data ? (
            <SearchError error={search.error} onRetry={() => void search.refetch()} />
          ) : search.isPending ? (
            <div className="space-y-3" aria-busy="true" aria-label="Loading">
              {Array.from({ length: 4 }, (_, i) => (
                <Skeleton key={i} className="h-24 w-full rounded-2xl" />
              ))}
            </div>
          ) : results.length ? (
            <LayoutGroup id="search">
              <ul className="space-y-3">
                <AnimatePresence mode="popLayout" initial={false}>
                  {results.map((p, i) => (
                    <motion.li
                      key={p.id}
                      layout
                      initial={{ opacity: 0, y: 10, scale: 0.98 }}
                      animate={{ opacity: 1, y: 0, scale: 1 }}
                      exit={{ opacity: 0, scale: 0.96, transition: { duration: 0.15 } }}
                      transition={{ type: 'spring', stiffness: 400, damping: 32, delay: Math.min(i, 8) * 0.02 }}
                    >
                      <SearchResult product={p} tokens={tokens} />
                    </motion.li>
                  ))}
                </AnimatePresence>
              </ul>
              <LoadMore hasNextPage={search.hasNextPage} loading={search.isFetchingNextPage} failed={search.isFetchNextPageError} onClick={() => void search.fetchNextPage()} />
            </LayoutGroup>
          ) : (
            <EmptyState
              icon={<SearchX className="size-6" />}
              title="No matches"
              body={`We couldn't find anything for “${hasQuery ? term : topic?.name}”. Try fewer words or another topic.`}
              action={
                <button onClick={() => setParams({}, { replace: true })} className="cursor-pointer text-sm font-semibold text-brand-600 hover:underline">
                  Clear search
                </button>
              }
            />
          )}
        </>
      ) : (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="mt-8 space-y-8">
          {recent.length > 0 && (
            <section>
              <div className="mb-3 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-sm font-bold tracking-wide text-ink-500 uppercase">
                  <History className="size-4" /> Recent searches
                </h2>
                <button
                  onClick={() => setRecent(clearRecent())}
                  className="cursor-pointer text-xs font-semibold text-ink-400 hover:text-ink-700"
                >
                  Clear
                </button>
              </div>
              <div className="flex flex-wrap gap-2">
                <AnimatePresence>
                  {recent.map((r) => (
                    <motion.button
                      key={r}
                      layout
                      initial={{ opacity: 0, scale: 0.8 }}
                      animate={{ opacity: 1, scale: 1 }}
                      exit={{ opacity: 0, scale: 0.8 }}
                      whileHover={{ y: -2 }}
                      whileTap={{ scale: 0.95 }}
                      onClick={() => setParam('q', r)}
                      className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-ink-200 bg-white px-3 py-1.5 text-sm font-medium text-ink-700 hover:border-brand-300 dark:border-white/10 dark:bg-white/5 dark:text-ink-200"
                    >
                      <Clock className="size-3.5 text-ink-400" /> {r}
                    </motion.button>
                  ))}
                </AnimatePresence>
              </div>
            </section>
          )}

          <section>
            <h2 className="mb-3 flex items-center gap-2 text-sm font-bold tracking-wide text-ink-500 uppercase">
              <Sparkles className="size-4" /> Try searching for
            </h2>
            <div className="flex flex-wrap gap-2">
              {['AI', 'notes', 'design', 'habit', 'freelancers', ...(topics.data ?? []).slice(0, 3).map((t) => t.name.toLowerCase())].map((s, i) => (
                <motion.button
                  key={s}
                  initial={{ opacity: 0, y: 6 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: i * 0.03 }}
                  whileHover={{ y: -2 }}
                  onClick={() => setParam('q', s)}
                  className="cursor-pointer rounded-full bg-ink-100 px-3 py-1.5 text-sm font-medium text-ink-600 hover:bg-brand-50 hover:text-brand-700 dark:bg-white/5 dark:text-ink-300"
                >
                  {s}
                </motion.button>
              ))}
            </div>
          </section>

          <section>
            <h2 className="mb-3 flex items-center gap-2 text-sm font-bold tracking-wide text-ink-500 uppercase">
              <Flame className="size-4" /> Most voted ever
            </h2>
            {popular.isPending ? (
              <Skeleton className="h-24 w-full rounded-2xl" />
            ) : popular.isError ? (
              <ErrorState error={popular.error} onRetry={() => void popular.refetch()} title="Could not load popular products" />
            ) : (
              <ul className="space-y-3">
                {popular.data.slice(0, 5).map((p, i) => (
                  <motion.li key={p.id} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 + i * 0.05 }}>
                    <SearchResult product={p} tokens={[]} />
                  </motion.li>
                ))}
              </ul>
            )}
          </section>
        </motion.div>
      )}
    </div>
  )
}
