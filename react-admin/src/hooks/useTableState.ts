import { useState, useCallback } from 'react'
import type { TableState } from '@/types'

interface UseTableStateOptions {
  defaultSortBy?: string
  defaultSortDir?: 'asc' | 'desc'
  defaultPerPage?: number
}

export function useTableState(options: UseTableStateOptions = {}) {
  const {
    defaultSortBy = 'created_at',
    defaultSortDir = 'desc',
    defaultPerPage = 20,
  } = options

  const [state, setState] = useState<TableState>({
    page: 1,
    perPage: defaultPerPage,
    sortBy: defaultSortBy,
    sortDir: defaultSortDir,
    search: '',
  })

  const setPage = useCallback((page: number) => setState((s) => ({ ...s, page })), [])

  const setSearch = useCallback(
    (search: string) => setState((s) => ({ ...s, search, page: 1 })),
    [],
  )

  const setSort = useCallback((sortBy: string) => {
    setState((s) => ({
      ...s,
      sortBy,
      sortDir: s.sortBy === sortBy && s.sortDir === 'asc' ? 'desc' : 'asc',
      page: 1,
    }))
  }, [])

  const setPerPage = useCallback(
    (perPage: number) => setState((s) => ({ ...s, perPage, page: 1 })),
    [],
  )

  const reset = useCallback(
    () =>
      setState({
        page: 1,
        perPage: defaultPerPage,
        sortBy: defaultSortBy,
        sortDir: defaultSortDir,
        search: '',
      }),
    [defaultPerPage, defaultSortBy, defaultSortDir],
  )

  /** Build URLSearchParams to append to an API request */
  const toParams = useCallback(
    (extra?: Record<string, string | number | undefined>) => {
      const params: Record<string, string> = {
        page: String(state.page),
        per_page: String(state.perPage),
        sort_by: state.sortBy,
        sort_order: state.sortDir,
      }
      if (state.search) params.search = state.search
      if (extra) {
        for (const [k, v] of Object.entries(extra)) {
          if (v !== undefined) params[k] = String(v)
        }
      }
      return params
    },
    [state],
  )

  return { state, setPage, setSearch, setSort, setPerPage, reset, toParams }
}
