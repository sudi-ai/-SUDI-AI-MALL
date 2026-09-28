import request from "@/utils/request.js";

export function sudiAiCapabilities() {
  return request.get("sudi/ai/capabilities", {}, { noAuth: true });
}

export function sudiAiShopping(data) {
  return request.post("sudi/ai/shopping", data, { noAuth: true });
}

export function sudiAiSizeAdvice(data) {
  return request.post("sudi/ai/size-advice", data);
}

export function sudiAiOutfit(data) {
  return request.post("sudi/ai/outfit", data, { noAuth: true });
}

export function sudiAiCustomer(data) {
  return request.post("sudi/ai/customer", data);
}
