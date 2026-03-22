import { useEffect, useReducer } from 'react';
import { getCustomerEventContextApi } from '../api/customer';

const INITIAL_STATE = {
  eventContext: null,
  eventLoading: true,
  eventError: '',
};

function eventContextReducer(state, action) {
  switch (action.type) {
    case 'invalid':
      return {
        eventContext: null,
        eventLoading: false,
        eventError: 'Evento inválido.',
      };
    case 'loading':
      return {
        eventContext: null,
        eventLoading: true,
        eventError: '',
      };
    case 'success':
      return {
        eventContext: action.payload,
        eventLoading: false,
        eventError: '',
      };
    case 'error':
      return {
        eventContext: null,
        eventLoading: false,
        eventError: action.payload,
      };
    default:
      return state;
  }
}

export function useCustomerEventContext(slug) {
  const [state, dispatch] = useReducer(
    eventContextReducer,
    String(slug || '').trim(),
    (initialSlug) => (initialSlug ? INITIAL_STATE : {
      eventContext: null,
      eventLoading: false,
      eventError: 'Evento inválido.',
    }),
  );

  useEffect(() => {
    let active = true;
    const normalizedSlug = String(slug || '').trim();

    if (!normalizedSlug) {
      dispatch({ type: 'invalid' });
      return undefined;
    }

    dispatch({ type: 'loading' });

    getCustomerEventContextApi({ slug: normalizedSlug })
      .then((data) => {
        if (!active) {
          return;
        }
        dispatch({ type: 'success', payload: data || null });
      })
      .catch((err) => {
        if (!active) {
          return;
        }
        dispatch({
          type: 'error',
          payload: err?.response?.data?.message || 'Nao foi possivel carregar o evento.',
        });
      });

    return () => {
      active = false;
    };
  }, [slug]);

  return state;
}
