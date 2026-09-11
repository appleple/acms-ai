interface PostRequestProps {
  url: string;
  data: {
    mode: string
    prompt?: {
      role: string
      content: string
    }[]
    [key: string]: unknown
  }
  exec: string
  formToken: string
  signal?: AbortSignal
}

/**
 * ACMS のブログ解決は URL コンテキストで行われるため、
 * 現在ブログが子ブログのときは POST 先 URL に `bid/<id>/` を含める。
 */
const resolveBlogUrl = (baseUrl: string): string => {
  const bid = window.ACMS?.Config?.bid
  if (bid === undefined || bid === null || bid === '') return baseUrl
  if (/\/bid\/[^/]+\/?$/.test(baseUrl)) return baseUrl
  const normalized = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`
  return `${normalized}bid/${bid}/`
}

interface ErrorPayload {
  message?: unknown
  errorCode?: unknown
}

const parseJsonResponse = async (response: Response): Promise<unknown | null> => {
  try {
    return await response.json()
  } catch {
    return null
  }
}

export const parseErrorMessage = (body: string): string | null => {
  try {
    const payload = JSON.parse(body) as ErrorPayload
    return typeof payload.message === 'string' && payload.message.trim() !== ''
      ? payload.message.trim()
      : null
  } catch {
    return null
  }
}

export const postRequest = async (props: PostRequestProps) => {
  const { url, data, exec, formToken, signal } = props

  const formData = new FormData()
  if (data.prompt !== undefined) {
    formData.append('prompt', JSON.stringify(data.prompt))
  }
  formData.append('mode', data.mode)
  Object.keys(data).forEach(key => {
    if (key !== 'prompt' && key !== 'mode') {
      formData.append(key, String(data[key]))
    }
  })
  formData.append(exec, 'exec')
  formData.append('formToken', formToken)

  const response = await fetch(resolveBlogUrl(url), {
    method: 'POST',
    body: formData,
    signal,
  })

  const payload = await parseJsonResponse(response)
  if (!response.ok) {
    return payload ?? {
      message: `サーバーからエラーが返されました。(${response.status})`,
      errorCode: response.status,
    }
  }

  return payload
}

interface PostStreamingRequestProps {
  url: string;
  data: {
    input: string;
    previousResponseId?: string;
    [key: string]: unknown;
  };
  exec: string;
  formToken: string;
}

export type PostStreamingResult =
  | { ok: true; response: Response }
  | { ok: false; status: number; errorBody: string };

export const postStreamingRequest = async (
  props: PostStreamingRequestProps
): Promise<PostStreamingResult> => {
  const { url, data, exec, formToken } = props;

  const formData = new FormData();
  formData.append('input', data.input);
  if (data.previousResponseId) {
    formData.append('previousResponseId', data.previousResponseId);
  }
  Object.keys(data).forEach((key) => {
    if (key !== 'input' && key !== 'previousResponseId') {
      const value = data[key];
      formData.append(key, String(value));
    }
  });
  formData.append(exec, 'exec');
  formData.append('formToken', formToken);

  const response = await fetch(resolveBlogUrl(url), {
    method: 'POST',
    body: formData,
  });

  if (!response.ok || !response.body) {
    const errorBody = await response.text().catch(() => '')
    return { ok: false, status: response.status, errorBody }
  }

  return { ok: true, response }
};
